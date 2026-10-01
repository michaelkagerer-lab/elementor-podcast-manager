<?php
/**
 * Integration tests for the feed build, its cache, feed HTTP semantics,
 * the upgrade routine and the readiness report on larger catalogs,
 * executed inside WordPress after the fixtures are seeded
 * (tests/fixtures/seed.php):
 *
 *   wp eval-file tests/integration/feed.php
 *
 * - the upgrade stores the version first, queues the per-episode work and
 *   finishes it in batches, under a memory limit far below the catalog.
 *
 * Episodes created here are deleted and the options changed here are
 * restored at the end.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\AdminPages;
use EPM\Directories;
use EPM\EpisodePostType;
use EPM\Episodes;
use EPM\Feed;
use EPM\FeedStore;
use EPM\FeedWriter;
use EPM\Hosting;
use EPM\PodcastSettings;
use EPM\Readiness;
use EPM\Upgrade;

require_once __DIR__ . '/lib.php';
require_once dirname( __DIR__ ) . '/perf/catalog.php';
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

EPM_Test_HTTP::$offline = true;
EPM_Test_HTTP::$routes  = [];

// What the run changes, to put back at the end.
$GLOBALS['epm_f_max_id']  = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts}" );
$GLOBALS['epm_f_options'] = [];
foreach ( [ PodcastSettings::OPTION, Hosting::OPTION, Directories::OPTION, Feed::BUILD_OPTION, 'epm_version', 'blogname', 'permalink_structure', 'powerpress_general' ] as $epm_f_name ) {
	$GLOBALS['epm_f_options'][ $epm_f_name ] = get_option( $epm_f_name, '__epm_absent__' );
}

/**
 * Put an option back as it was before the run.
 *
 * @param string $name Option.
 * @return void
 */
function epm_f_restore( string $name ): void {
	$value = $GLOBALS['epm_f_options'][ $name ] ?? '__epm_absent__';
	if ( '__epm_absent__' === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

/**
 * Change podcast settings through the sanitizer.
 *
 * @param array $changes Values.
 * @return void
 */
function epm_f_settings( array $changes ): void {
	$settings = epm()->settings;
	update_option( PodcastSettings::OPTION, $settings->sanitize( array_merge( $settings->all(), $changes ) ) );
}

/**
 * The feed without <lastBuildDate> (the build time differs between builds).
 *
 * @param string $xml Feed.
 * @return string
 */
function epm_f_strip( string $xml ): string {
	return (string) preg_replace( '#<lastBuildDate>[^<]*</lastBuildDate>#', '', $xml );
}

/**
 * The 1.3 builder's feed for the current settings.
 *
 * @return string
 */
function epm_f_reference_xml(): string {
	$reference = new \EPM\Reference\Feed();

	return $reference->build( $reference->eligible_episodes(), 0 );
}

/**
 * Create a published episode with external audio.
 *
 * @param string $title Title.
 * @param string $date  post_date.
 * @param array  $meta  Meta without prefix.
 * @return int
 */
function epm_f_episode( string $title, string $date, array $meta = [] ): int {
	$id = (int) wp_insert_post(
		[
			'post_type'    => EpisodePostType::CPT,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => '<p>Notes for ' . esc_html( $title ) . '.</p>',
			'post_date'    => $date,
		]
	);
	$meta += [
		'audio_url'    => 'https://feeds.example.test/media/' . sanitize_title( $title ) . '.mp3',
		'audio_type'   => 'audio/mpeg',
		'audio_length' => 123456,
	];
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, Episodes::META_PREFIX . $key, $value );
	}

	return $id;
}

/**
 * Serve the feed like /podcast/feed/ does and return what was sent.
 *
 * @param array<string, string> $server $_SERVER entries (request headers).
 * @return array{status: int, body: string}
 */
function epm_f_serve( array $server = [] ): array {
	$status = 200;
	$filter = static function ( $header, $code ) use ( &$status ) {
		$status = (int) $code;
		return $header;
	};
	add_filter( 'status_header', $filter, 10, 2 );
	$saved = [];
	foreach ( $server as $key => $value ) {
		$saved[ $key ] = $_SERVER[ $key ] ?? null;
		$_SERVER[ $key ] = $value;
	}
	ob_start();
	epm()->feed->serve_feed();
	$body = (string) ob_get_clean();
	foreach ( $saved as $key => $value ) {
		if ( null === $value ) {
			unset( $_SERVER[ $key ] );
		} else {
			$_SERVER[ $key ] = $value;
		}
	}
	remove_filter( 'status_header', $filter, 10 );

	return [
		'status' => $status,
		'body'   => $body,
	];
}

$t = new EPM_Test_Runner();

/* ------------------------------------------------------------------------- */
/* LIFE-N1: the upgrade                                                      */
/* ------------------------------------------------------------------------- */

$t->test(
	'LIFE-N1: after an update the first request stores the version at once and does no per-episode work, even with 40 MB of transcripts under a 24 MB memory headroom; the batches finish it',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		epm_perf_catalog( 100, 400, false );
		$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE meta_key = '_epm_duration_seconds'" );
		wp_cache_flush();
		update_option( 'epm_version', '1.2.0' );

		$limit = ini_get( 'memory_limit' );
		gc_collect_cycles();
		ini_set( 'memory_limit', (string) ( (int) ceil( memory_get_usage() / MB_IN_BYTES ) + 24 ) . 'M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		$queries = $wpdb->num_queries;
		epm()->maybe_upgrade();
		$t->same( EPM_VERSION, (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'epm_version'" ), 'version stored' );
		$t->same( [ 'durations', 'guid_rows' ], Upgrade::pending(), 'per-episode work queued' );
		$t->assert( false !== wp_next_scheduled( Upgrade::HOOK ), 'and scheduled' );
		$t->assert( $wpdb->num_queries - $queries < 60, ( $wpdb->num_queries - $queries ) . ' queries' );
		$t->same( '0', (string) $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta} p INNER JOIN {$wpdb->posts} e ON e.ID = p.post_id WHERE p.meta_key = '_epm_duration_seconds' AND e.post_name = 'perf-cat-1'" ), 'no episode touched in the request' );

		$second = $wpdb->num_queries;
		epm()->maybe_upgrade();
		$t->assert( $wpdb->num_queries - $second <= 1, 'the next request does nothing' );

		$runs = 0;
		do {
			$result = Upgrade::run( 0.5 );
			++$runs;
		} while ( ! empty( $result['pending'] ) && $runs < 50 );
		ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		$t->same( [], Upgrade::pending(), 'finished in ' . $runs . ' runs' );
		$t->same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} p INNER JOIN {$wpdb->posts} e ON e.ID = p.post_id WHERE p.meta_key = '_epm_duration_seconds' AND p.meta_value <> '600' AND e.post_name LIKE 'perf-cat-%'" ), 'every duration synced' );
		$t->same( false, wp_next_scheduled( Upgrade::HOOK ), 'nothing scheduled' );
		epm_perf_catalog_reset();
	}
);

$t->test(
	'LIFE-N1: two requests that see the old version: only one claims the upgrade; a held lock keeps a second worker out',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		update_option( 'epm_version', '1.2.0' );
		// Another request stored the new version after this one read the old.
		$wpdb->update( $wpdb->options, [ 'option_value' => EPM_VERSION ], [ 'option_name' => 'epm_version' ] );
		$flushed = 0;
		$count   = static function () use ( &$flushed ) {
			++$flushed;
		};
		add_action( 'generate_rewrite_rules', $count );
		epm()->maybe_upgrade();
		remove_action( 'generate_rewrite_rules', $count );
		$t->same( 0, $flushed, 'the request that lost does no upgrade work' );
		$t->same( EPM_VERSION, (string) get_option( 'epm_version' ), 'and reads the new version afterwards' );

		Upgrade::queue( '1.2.0' );
		$lock = \EPM\OptionRow::lock( Upgrade::LOCK, 300 );
		$busy = Upgrade::run( 1.0 );
		$t->assert( $busy['busy'] && 0 === $busy['batches'], 'a second worker waits' );
		\EPM\OptionRow::unlock( Upgrade::LOCK, $lock );
		$t->same( [], Upgrade::run( 0 )['pending'], 'then the work finishes' );
	}
);

/* ------------------------------------------------------------------------- */
/* Put the site back                                                         */
/* ------------------------------------------------------------------------- */

EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$offline = false;
epm_perf_catalog_reset();

$epm_f_created = array_map( 'intval', $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE ID > %d ORDER BY ID DESC", $GLOBALS['epm_f_max_id'] ) ) );
foreach ( $epm_f_created as $epm_f_id ) {
	wp_delete_post( $epm_f_id, true );
}
foreach ( array_keys( $GLOBALS['epm_f_options'] ) as $epm_f_name ) {
	epm_f_restore( $epm_f_name );
}
\EPM\OptionRow::delete( Upgrade::STATE );
\EPM\OptionRow::delete( Upgrade::LOCK );
wp_clear_scheduled_hook( Upgrade::HOOK );
flush_rewrite_rules( false );
Feed::flush_cache();

$t->finish();
