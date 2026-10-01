<?php
/**
 * Integration tests for the import job's data integrity, executed inside
 * WordPress after the fixtures are seeded (tests/fixtures/seed.php):
 *
 *   wp eval-file tests/integration/import.php
 *
 * Covers what one process can show: the sync stopping when its lock is
 * taken over, the episode GUID check right before an episode is created,
 * paged feeds (completeness, stop reasons, resume, budgets), where the
 * parsed feed is stored and when it is removed. Races between two real
 * processes are in tests/concurrency/.
 *
 * Feeds are served by the fixture HTTP server
 * (tests/fixtures/mu-plugins/epm-test-http.php); every other outgoing
 * request is refused. Episodes created here use GUIDs starting with
 * "imp-" and are deleted again; the options changed here are restored.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Feed;
use EPM\Hosting;
use EPM\ImportJob;
use EPM\Importer;
use EPM\PodcastSettings;

require_once __DIR__ . '/lib.php';
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

EPM_Test_HTTP::$offline = true;
EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$log     = [];

$GLOBALS['epm_i_options'] = [];
foreach ( [ PodcastSettings::OPTION, Hosting::OPTION, Hosting::STATE_OPTION, ImportJob::OPTION, 'epm_import_lock', Feed::GUID_OPTION, Feed::BUILD_OPTION ] as $epm_i_name ) {
	$GLOBALS['epm_i_options'][ $epm_i_name ] = get_option( $epm_i_name, '__epm_absent__' );
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------- */

/**
 * One feed item.
 *
 * @param string $guid  GUID.
 * @param int    $time  Publish time.
 * @param string $notes Show notes.
 * @return string
 */
function epm_i_item( string $guid, int $time, string $notes = '' ): string {
	return '<item><title>Episode ' . esc_xml( $guid ) . '</title><guid isPermaLink="false">' . esc_xml( $guid ) . '</guid>'
		. '<pubDate>' . gmdate( DATE_RSS, $time ) . '</pubDate>'
		. '<description><![CDATA[' . ( '' !== $notes ? $notes : '<p>Notes for ' . esc_html( $guid ) . '</p>' ) . ']]></description>'
		. '<enclosure url="https://feeds.example.test/media/' . rawurlencode( $guid ) . '.mp3" length="4000" type="audio/mpeg"/>'
		. '<itunes:duration>00:05</itunes:duration></item>';
}

/**
 * A feed page.
 *
 * @param string   $title Show title.
 * @param string[] $items Item XML.
 * @param string   $next  rel="next" address ('' for none).
 * @return string
 */
function epm_i_page( string $title, array $items, string $next = '' ): string {
	return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
		. '<title>' . esc_xml( $title ) . '</title><link>https://show.example.test/</link><description>Import test show</description><language>en</language>'
		. '<itunes:author>Import test</itunes:author><itunes:owner><itunes:name>Import test</itunes:name><itunes:email>owner@example.test</itunes:email></itunes:owner>'
		. ( '' !== $next ? '<atom:link href="' . esc_attr( $next ) . '" rel="next" type="application/rss+xml"/>' : '' )
		. implode( '', $items ) . '</channel></rss>';
}

/**
 * Serve a body (or a callable answer) at a URL.
 *
 * @param string                $url    URL.
 * @param string|callable|array $answer Feed XML, a response array or a callable.
 * @return void
 */
function epm_i_route( string $url, $answer ): void {
	EPM_Test_HTTP::$routes[ $url ] = is_callable( $answer ) ? $answer : static function () use ( $answer ) {
		return is_array( $answer ) ? $answer : EPM_Test_HTTP::response( 200, (string) $answer, [ 'content-type' => 'application/rss+xml' ] );
	};
}

/**
 * Episodes per GUID for a prefix (any status), duplicates included.
 *
 * @param string $prefix GUID prefix.
 * @return array<string, int>
 */
function epm_i_guids( string $prefix ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value AS guid, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_epm_guid' AND pm.meta_value LIKE %s AND p.post_type = 'podcast_episode' GROUP BY pm.meta_value",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
	$out  = [];
	foreach ( (array) $rows as $row ) {
		$out[ (string) $row->guid ] = (int) $row->n;
	}
	ksort( $out );

	return $out;
}

/**
 * Delete the episodes of a GUID prefix.
 *
 * @param string $prefix GUID prefix.
 * @return void
 */
function epm_i_delete( string $prefix ): void {
	global $wpdb;

	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epm_guid' AND meta_value LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $ids as $id ) {
		foreach ( get_children( [ 'post_parent' => (int) $id, 'post_type' => 'attachment', 'fields' => 'ids' ] ) as $attachment ) {
			wp_delete_attachment( (int) $attachment, true );
		}
		wp_delete_post( (int) $id, true );
	}
}

/**
 * Put an option back as it was before the run.
 *
 * @param string $name Option.
 * @return void
 */
function epm_i_restore( string $name ): void {
	$value = $GLOBALS['epm_i_options'][ $name ] ?? '__epm_absent__';
	if ( '__epm_absent__' === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

/**
 * The lock row, read past every cache ('' when there is none).
 *
 * @return string
 */
function epm_i_lock_row(): string {
	global $wpdb;

	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'epm_import_lock' ) );
}

$t = new EPM_Test_Runner();

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Lock and job integrity' );
/* ------------------------------------------------------------------------- */

$t->test(
	'a sync whose lock another request took over stops before the next episode and reports it',
	static function ( EPM_Test_Runner $t ) {
		$url   = 'https://feeds.example.test/imp/sync-takeover.xml';
		$items = [];
		for ( $k = 6; $k >= 1; $k-- ) {
			$items[] = epm_i_item( 'imp-sync-' . $k, gmmktime( 8, 0, 0, 3, $k, 2026 ) );
		}
		epm_i_route( $url, EPM_Test_HTTP::response( 200, epm_i_page( 'Takeover show', $items ), [ 'content-type' => 'application/rss+xml', 'etag' => '"takeover-1"' ] ) );
		update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), [ 'mode' => 'external', 'feed_url' => $url, 'sync' => false ] ) ) );
		delete_option( Hosting::STATE_OPTION );

		// After the second new episode, another request takes the lock over
		// (this sync looked abandoned to it).
		$other   = time() . ':another-request';
		$created = 0;
		$take    = static function ( $id, $post ) use ( &$created, $other ) {
			global $wpdb;
			if ( 'podcast_episode' === $post->post_type && 2 === ++$created ) {
				$wpdb->update( $wpdb->options, [ 'option_value' => $other ], [ 'option_name' => 'epm_import_lock' ] );
			}
		};
		add_action( 'wp_insert_post', $take, 10, 2 );
		try {
			$result = Hosting::sync( true );
			$t->same( 2, count( epm_i_guids( 'imp-sync-' ) ), 'no episode after the takeover' );
			$t->assert( 'ok' !== $result['status'], 'not reported as a normal sync (' . $result['status'] . ')' );
			$t->same( $other, epm_i_lock_row(), 'the other request\'s lock stays' );
			$t->same( '', (string) ( Hosting::state()['etag'] ?? '' ), 'no validators stored, so the next sync reads the whole feed' );
		} finally {
			remove_action( 'wp_insert_post', $take, 10 );
			global $wpdb;
			$wpdb->delete( $wpdb->options, [ 'option_name' => 'epm_import_lock' ] );
			wp_cache_delete( 'epm_import_lock', 'options' );
			unset( EPM_Test_HTTP::$routes[ $url ] );
			epm_i_delete( 'imp-sync-' );
			epm_i_restore( Hosting::OPTION );
			epm_i_restore( Hosting::STATE_OPTION );
		}
	}
);

$t->test(
	'an episode another request created after the GUID list was read is updated, not created twice',
	static function ( EPM_Test_Runner $t ) {
		$importer = new Importer( [ 'feed_url' => 'https://feeds.example.test/imp/guid.xml' ] );
		$importer->guid_map();
		// Meanwhile another request imports the same episode.
		$other = wp_insert_post(
			[
				'post_type'   => 'podcast_episode',
				'post_title'  => 'Episode imp-guid-1',
				'post_status' => 'publish',
				'meta_input'  => [ '_epm_guid' => 'imp-guid-1' ],
			]
		);
		try {
			$feed    = ( new \EPM\FeedParser() )->parse( epm_i_page( 'GUID show', [ epm_i_item( 'imp-guid-1', gmmktime( 8, 0, 0, 3, 1, 2026 ) ) ] ) );
			$outcome = $importer->import_item( $feed['items'][0] );
			$t->same( [ 'imp-guid-1' => 1 ], epm_i_guids( 'imp-guid-' ), 'one episode' );
			$t->assert( 'created' !== $outcome['action'], 'not counted as new (' . $outcome['action'] . ')' );
			$t->same( (int) $other, (int) $outcome['id'], 'the existing episode' );
		} finally {
			epm_i_delete( 'imp-guid-' );
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Put the site back                                                         */
/* ------------------------------------------------------------------------- */

EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$offline = false;
foreach ( array_keys( $GLOBALS['epm_i_options'] ) as $epm_i_name ) {
	epm_i_restore( $epm_i_name );
}
wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
Feed::flush_cache();

$t->finish();
