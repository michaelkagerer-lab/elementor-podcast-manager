<?php
/** Import overhead regressions (PERF-N5): real batch writes and cold log queries. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/lib.php';
$job_class = $GLOBALS['epm_test_import_job_class'] ?? EPM\ImportJob::class;
$t = new EPM_Test_Runner();
$snapshots = [];
foreach ( [ EPM\ImportJob::OPTION, 'epm_import_lock', 'cron' ] as $name ) {
	$snapshots[ $name ] = epm_test_option_snapshot( $name );
}
$ids = [];
$old_user = get_current_user_id();
wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
try {
	$t->test( 'A crashed media-copy request releases its stale lock after five minutes', static function ( $t ) {
		EPM\ImportJob::release_lock();
		update_option( EPM\ImportJob::OPTION, [ 'status' => 'running', 'options' => [ 'download_media' => true ] ] );
		update_option( 'epm_import_lock', ( time() - 6 * MINUTE_IN_SECONDS ) . ':crashed-media-worker', false );
		$t->assert( EPM\ImportJob::acquire_lock(), 'six-minute abandoned media lock can be recovered' );
		EPM\ImportJob::release_lock();
		update_option( 'epm_import_lock', ( time() - 4 * MINUTE_IN_SECONDS ) . ':recent-media-worker', false );
		$t->assert( ! EPM\ImportJob::acquire_lock(), 'recent media worker remains protected' );
		delete_option( 'epm_import_lock' );
		delete_option( EPM\ImportJob::OPTION );
	} );
	$t->test( 'PERF-N5: ten imported episodes invalidate the feed once per unfinished batch', static function ( $t ) use ( $job_class, &$ids ) {
		$job_class::cancel();
		$url = 'https://feeds.example.test/overhead.xml';
		$xml = '<rss version="2.0"><channel><title>Overhead</title><description>Budget test</description>';
		for ( $i = 0; $i < 20; ++$i ) {
			$xml .= '<item><title>Overhead ' . $i . '</title><guid>overhead-' . $i . '</guid><pubDate>' . gmdate( DATE_RSS, time() - 1000 - $i ) . '</pubDate><enclosure url="https://feeds.example.test/' . $i . '.mp3" type="audio/mpeg" length="2000"/></item>';
		}
		$xml .= '</channel></rss>';
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $xml ) { return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] ); };
		$preview = $job_class::preview( $url );
		for ( $i = 0; ! is_wp_error( $preview ) && ! empty( $preview['loading'] ) && $i < 10; ++$i ) {
			$preview = $job_class::preview_more( $preview['token'] );
		}
		$t->assert( ! is_wp_error( $preview ), 'feed preview succeeds' );
		if ( is_wp_error( $preview ) ) { return; }
		$job_class::start( $preview['token'], [ 'status' => 'publish', 'download_media' => false, 'apply_channel' => false ] );
		$flushes = 0;
		$watch = static function ( $sql ) use ( &$flushes ) {
			if ( 0 === stripos( ltrim( $sql ), 'DELETE' ) && false !== strpos( $sql, "'epm_feed_cache'" ) ) { ++$flushes; }
			return $sql;
		};
		add_filter( 'query', $watch );
		try { $state = $job_class::step( 60 ); } finally { remove_filter( 'query', $watch ); }
		$t->same( 10, $state['done'] ?? 0, 'real ten-item batch' );
		$t->same( 'running', $state['status'] ?? '', 'unfinished import' );
		$t->same( 1, $flushes, 'coalesced invalidation' );
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epm_guid' AND meta_value LIKE 'overhead-%'" ) );
		$t->same( 10, count( $ids ), 'episodes persisted' );
	} );
	$t->test( 'PERF-N5: a cold fifty-entry import log hydrates posts in one query', static function ( $t ) use ( $job_class, &$ids ) {
		while ( count( $ids ) < 50 ) {
			$ids[] = wp_insert_post( [ 'post_type' => 'podcast_episode', 'post_status' => 'publish', 'post_title' => 'Cold log ' . count( $ids ) ] );
		}
		$log = [];
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			$log[] = [ 'id' => $id, 'title' => 'Episode', 'action' => 'created', 'message' => '' ];
		}
		$reads = 0;
		$watch = static function ( $sql ) use ( &$reads ) {
			global $wpdb;
			if ( 0 === stripos( ltrim( $sql ), 'SELECT' ) && preg_match( '/FROM\s+`?' . preg_quote( $wpdb->posts, '/' ) . '`?\b/i', $sql ) ) { ++$reads; }
			return $sql;
		};
		add_filter( 'query', $watch );
		try { $state = $job_class::client_state( [ 'status' => 'cancelled', 'log' => $log ] ); } finally { remove_filter( 'query', $watch ); }
		$t->same( 50, count( $state['log'] ), 'all entries retained' );
		$t->assert( $reads <= 1, 'bounded post reads, got ' . $reads );
		$t->assert( count( array_filter( array_column( $state['log'], 'edit' ) ) ) === 50, 'every editable episode has its link' );
	} );
} finally {
	$job_class::cancel();
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	foreach ( $snapshots as $name => $snapshot ) { epm_test_option_restore( $name, $snapshot ); }
	wp_set_current_user( $old_user );
}
$t->finish();
