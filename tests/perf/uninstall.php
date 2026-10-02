<?php
/** LIFE-N5: explicit deletion budget; disposable marked MySQL network ONLY. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'EPM_TEST_SITE' ) || ! is_file( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.epm-test-site' ) ) {
	throw new RuntimeException( 'Requires a marked disposable test site and EPM_TEST_SITE=1.' );
}
require_once __DIR__ . '/../integration/lib.php';
// Load class definitions without activating the plugin or its runtime hooks.
require_once dirname( __DIR__, 2 ) . '/elementor-podcast-manager.php';
require_once __DIR__ . '/catalog.php';
$t = new EPM_Test_Runner();
epm_perf_catalog( 5000, 4, false );
global $wpdb;
$parent = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'podcast_episode' ORDER BY ID LIMIT 1" );
$revision = wp_insert_post( [ 'post_type' => 'revision', 'post_parent' => $parent, 'post_status' => 'inherit', 'post_title' => 'Disposable revision', 'meta_input' => [ 'probe' => 'delete me' ] ] );
$attachment = wp_insert_post( [ 'post_type' => 'attachment', 'post_parent' => $parent, 'post_status' => 'inherit', 'post_title' => 'Keep my media' ] );
$comment = wp_insert_comment( [ 'comment_post_ID' => $parent, 'comment_content' => 'Disposable comment', 'comment_approved' => 1 ] );
update_comment_meta( $comment, 'probe', 'delete me' );
update_option( 'epm_podcast_guid', 'delete-only-after-episodes' );
$queries = $wpdb->num_queries;
$start = microtime( true );
define( 'WP_UNINSTALL_PLUGIN', 'elementor-podcast-manager/elementor-podcast-manager.php' );
define( 'EPM_DELETE_DATA', true );
include dirname( __DIR__, 2 ) . '/uninstall.php';
$seconds = microtime( true ) - $start;
$used = $wpdb->num_queries - $queries;
WP_CLI::log( sprintf( 'Uninstall: 5,000 episodes, %.3fs, %d queries.', $seconds, $used ) );
$t->assert( $seconds < 30, sprintf( '5,000-episode deletion finishes below 30s (%.2fs)', $seconds ) );
$t->assert( $used < 5000, "deletion uses bounded bulk queries ($used queries)" );
$t->same( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'podcast_episode'" ), 'all episodes deleted' );
$t->same( null, get_post( $revision ), 'episode revisions deleted' );
$t->same( [], get_post_meta( $revision ), 'revision metadata deleted' );
$t->same( null, get_comment( $comment ), 'episode comments deleted' );
$t->same( [], get_comment_meta( $comment ), 'comment metadata deleted' );
$t->assert( null !== get_post( $attachment ), 'Media Library attachment preserved' );
$t->same( '0', (string) get_post_field( 'post_parent', $attachment ), 'preserved media detached from deleted episode' );
$t->same( false, get_option( 'epm_podcast_guid', false ), 'explicit deletion removes settings after episode cleanup' );
wp_delete_attachment( $attachment, true );
register_post_type( 'podcast_episode' );
$probe = wp_insert_post( [ 'post_type' => 'podcast_episode', 'post_status' => 'publish', 'post_title' => 'Retry failed cleanup' ] );
update_post_meta( $probe, '_epm_guid', 'keep-on-database-error' );
update_option( 'epm_podcast_guid', 'keep-on-database-error' );
$reject = static function ( $query ) use ( $wpdb ) {
	return 0 === strpos( $query, "DELETE FROM {$wpdb->postmeta} WHERE post_id IN" ) ? 'EPM_TEST_INVALID_DELETE' : $query;
};
$suppressed = $wpdb->suppress_errors( true );
add_filter( 'query', $reject );
$stopped = false;
try {
	include dirname( __DIR__, 2 ) . '/uninstall.php';
} catch ( RuntimeException $error ) {
	$stopped = true;
} finally {
	remove_filter( 'query', $reject );
	$wpdb->suppress_errors( $suppressed );
}
$t->assert( $stopped, 'a failed database delete stops cleanup' );
$t->same( 'keep-on-database-error', get_option( 'epm_podcast_guid' ), 'settings remain available after failed cleanup' );
$t->assert( null !== get_post( $probe ), 'an episode is retained when its metadata delete fails' );
include dirname( __DIR__, 2 ) . '/uninstall.php';
$t->same( false, get_option( 'epm_podcast_guid', false ), 'cleanup can be retried successfully' );
$t->finish();
