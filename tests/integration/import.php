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

/**
 * A paged feed: $pages pages of $per items, newest first, each page
 * linking the next one with an absolute address.
 *
 * @param string $case  Name (part of the URLs and GUIDs).
 * @param int    $pages Pages.
 * @param int    $per   Items per page.
 * @return array<string, string> URL => feed XML.
 */
function epm_i_pages( string $case, int $pages = 3, int $per = 3 ): array {
	$base = 'https://feeds.example.test/imp/' . $case . '/';
	$all  = $pages * $per;
	$out  = [];
	for ( $p = 1; $p <= $pages; $p++ ) {
		$items = [];
		for ( $i = 0; $i < $per; $i++ ) {
			$k       = $all - ( ( $p - 1 ) * $per + $i );
			$items[] = epm_i_item( sprintf( 'imp-%s-%03d', $case, $k ), gmmktime( 8, 0, 0, 1, 1, 2025 ) + $k * DAY_IN_SECONDS );
		}
		$out[ $base . 'p' . $p . '.xml' ] = epm_i_page( 'Paged ' . $case, $items, $p < $pages ? $base . 'p' . ( $p + 1 ) . '.xml' : '' );
	}

	return $out;
}

/**
 * Serve every page of epm_i_pages() (answers given in $override win).
 *
 * @param array<string, string>       $pages    URL => XML.
 * @param array<string, string|array|callable> $override URL => answer.
 * @return string The first page's URL.
 */
function epm_i_serve( array $pages, array $override = [] ): string {
	foreach ( array_merge( $pages, $override ) as $url => $answer ) {
		epm_i_route( $url, $answer );
	}

	return (string) array_key_first( $pages );
}

/**
 * Check a feed the way the import screen does: the first request, then
 * further requests while the preview says pages are still being read.
 *
 * @param string $url Feed URL.
 * @return array|WP_Error The last summary.
 */
function epm_i_preview( string $url ) {
	$summary = ImportJob::preview( $url );
	for ( $i = 0; $i < 200 && is_callable( [ ImportJob::class, 'preview_more' ] ) && ! is_wp_error( $summary ) && ! empty( $summary['catalog']['loading'] ); $i++ ) {
		$summary = ImportJob::preview_more( (string) $summary['token'] );
	}

	return $summary;
}

/**
 * Start a previewed import and step it until it stops.
 *
 * @param array $summary Preview summary.
 * @param array $options Options for ImportJob::start().
 * @return array|WP_Error Final client state.
 */
function epm_i_run( array $summary, array $options ) {
	$state = ImportJob::start( (string) $summary['token'], $options );
	for ( $i = 0; $i < 300 && ! is_wp_error( $state ) && 'running' === $state['status']; $i++ ) {
		$state = ImportJob::step( 8.0 );
	}

	return $state;
}

/**
 * URLs requested since the log was cleared.
 *
 * @param string $contains Only URLs containing this.
 * @return string[]
 */
function epm_i_requested( string $contains ): array {
	return array_values(
		array_filter(
			array_column( EPM_Test_HTTP::$log, 'url' ),
			static function ( $url ) use ( $contains ) {
				return false !== strpos( $url, $contains );
			}
		)
	);
}

/**
 * Reset the import, the hosting mode and the move flags.
 *
 * @param array $hosting Hosting settings to apply.
 * @return void
 */
function epm_i_reset( array $hosting = [ 'mode' => 'self' ] ): void {
	ImportJob::cancel();
	EPM_Test_HTTP::$log = [];
	$settings             = epm()->settings->all();
	$settings['moved_in'] = false;
	$settings['locked']   = false;
	update_option( PodcastSettings::OPTION, epm()->settings->sanitize( $settings ) );
	update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), $hosting ) ) );
}

/**
 * Options rows that hold parsed feed data, grouped by job storage key.
 *
 * @return array<string, int> Key => rows.
 */
function epm_i_stored(): array {
	global $wpdb;

	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'epm_import_chunk_' ) . '%' ) );
	$keys  = [];
	foreach ( $names as $name ) {
		$parts = explode( '_', substr( (string) $name, strlen( 'epm_import_chunk_' ) ) );
		$key   = $parts[0] . '_' . ( $parts[1] ?? '' );
		$keys[ $key ] = ( $keys[ $key ] ?? 0 ) + 1;
	}

	return $keys;
}

/**
 * Files below wp-content/uploads whose contents include a string.
 *
 * @param string $needle Text.
 * @return string[] Paths relative to the uploads folder.
 */
function epm_i_uploads_containing( string $needle ): array {
	$base  = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
	$found = [];
	if ( ! is_dir( $base ) ) {
		return $found;
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( $file->isFile() && $file->getSize() < 64 * MB_IN_BYTES && false !== strpos( (string) file_get_contents( $file->getPathname() ), $needle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$found[] = substr( wp_normalize_path( $file->getPathname() ), strlen( $base ) + 1 );
		}
	}

	return $found;
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
WP_CLI::log( 'Paged feeds: completeness' );
/* ------------------------------------------------------------------------- */

$t->test(
	'page 2 answers HTTP 500: the preview says the catalog is incomplete, why, and which page',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$pages = epm_i_pages( 'p500' );
		$url   = epm_i_serve( $pages, [ 'https://feeds.example.test/imp/p500/p2.xml' => EPM_Test_HTTP::response( 500, 'Internal Server Error', [ 'content-type' => 'text/html' ] ) ] );
		$p     = epm_i_preview( $url );
		$t->same( 3, $p['episodes'] ?? null, 'the episodes read so far' );
		$t->same( false, $p['catalog']['complete'] ?? null, 'incomplete' );
		$t->same( 'http_error', $p['catalog']['reason'] ?? null );
		$t->same( 'https://feeds.example.test/imp/p500/p2.xml', $p['catalog']['url'] ?? null, 'the page that failed' );
		$t->assert( false !== strpos( (string) ( $p['catalog']['error'] ?? '' ), '500' ), 'the concrete error' );
		$t->same( 1, $p['catalog']['pages'] ?? null, 'pages read' );
		$t->assert( '' !== (string) ( $p['catalog']['message'] ?? '' ), 'an explanation for the screen' );
		$t->same( [], epm_i_requested( '/imp/p500/p3.xml' ), 'nothing after the failed page' );
	}
);

$t->test(
	'a transport error, invalid XML and an empty page that links on each stop the catalog with their reason',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'timeout' => [
				static function () {
					return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' );
				},
				'transport_error',
				'cURL error 28',
			],
			'badxml'  => [ null, 'parse_error', '' ],
			'empty'   => [ null, 'empty_page_with_next', '' ],
		];
		foreach ( $cases as $case => [ $answer, $reason, $error ] ) {
			epm_i_reset();
			$pages = epm_i_pages( $case );
			$p2    = 'https://feeds.example.test/imp/' . $case . '/p2.xml';
			if ( 'badxml' === $case ) {
				$answer = substr( $pages[ $p2 ], 0, 400 );
			} elseif ( 'empty' === $case ) {
				$answer = epm_i_page( 'Paged empty', [], 'https://feeds.example.test/imp/empty/p3.xml' );
			}
			$p = epm_i_preview( epm_i_serve( $pages, [ $p2 => $answer ] ) );
			$t->same( [ false, $reason, $p2, 3 ], [ $p['catalog']['complete'] ?? null, $p['catalog']['reason'] ?? null, $p['catalog']['url'] ?? null, $p['episodes'] ?? null ], $case );
			if ( '' !== $error ) {
				$t->assert( false !== strpos( (string) ( $p['catalog']['error'] ?? '' ), $error ), $case . ': the concrete error' );
			}
		}
	}
);

$t->test(
	'relative and root-relative next links are resolved against the page address',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ 'relative' => 'p2.xml', 'rootrel' => '/imp/rootrel/p2.xml' ] as $case => $href ) {
			epm_i_reset();
			$pages = epm_i_pages( $case );
			$first = (string) array_key_first( $pages );
			$pages[ $first ] = str_replace( 'href="https://feeds.example.test/imp/' . $case . '/p2.xml"', 'href="' . $href . '"', $pages[ $first ] );
			$p = epm_i_preview( epm_i_serve( $pages ) );
			$t->same( [ 'https://feeds.example.test/imp/' . $case . '/p2.xml' ], epm_i_requested( '/p2.xml' ), $case . ': the resolved address' );
			$t->same( [ true, 9 ], [ $p['catalog']['complete'] ?? null, $p['episodes'] ?? null ], $case . ': every page' );
		}

		// A next link that is no web address cannot be followed: that is no
		// end of the feed.
		epm_i_reset();
		$pages = epm_i_pages( 'badnext' );
		$first = (string) array_key_first( $pages );
		$pages[ $first ] = str_replace( 'href="https://feeds.example.test/imp/badnext/p2.xml"', 'href="ftp://feeds.example.test/imp/badnext/p2.xml"', $pages[ $first ] );
		$p = epm_i_preview( epm_i_serve( $pages ) );
		$t->same( [ false, 'parse_error', 'ftp://feeds.example.test/imp/badnext/p2.xml', 3 ], [ $p['catalog']['complete'] ?? null, $p['catalog']['reason'] ?? null, $p['catalog']['url'] ?? null, $p['episodes'] ?? null ], 'unusable next link' );
	}
);

$t->test(
	'the page limit (filter epm_import_max_pages) stops with page_limit; a cycle and duplicate GUIDs complete normally',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$limit = static function () {
			return 3;
		};
		add_filter( 'epm_import_max_pages', $limit );
		try {
			$p = epm_i_preview( epm_i_serve( epm_i_pages( 'limit', 5, 2 ) ) );
			$t->same( [ false, 'page_limit', 'https://feeds.example.test/imp/limit/p4.xml', 6, 3 ], [ $p['catalog']['complete'] ?? null, $p['catalog']['reason'] ?? null, $p['catalog']['url'] ?? null, $p['episodes'] ?? null, $p['catalog']['pages'] ?? null ], 'limit' );
			$t->same( [], epm_i_requested( '/imp/limit/p4.xml' ), 'the fourth page is not read' );
		} finally {
			remove_filter( 'epm_import_max_pages', $limit );
		}

		epm_i_reset();
		$pages = epm_i_pages( 'cycle' );
		$p3    = 'https://feeds.example.test/imp/cycle/p3.xml';
		$pages[ $p3 ] = str_replace( '</language>', '</language><atom:link href="https://feeds.example.test/imp/cycle/p2.xml" rel="next" type="application/rss+xml"/>', $pages[ $p3 ] );
		$p = epm_i_preview( epm_i_serve( $pages ) );
		$t->same( [ true, 'complete', 9 ], [ $p['catalog']['complete'] ?? null, $p['catalog']['reason'] ?? null, $p['episodes'] ?? null ], 'cycle' );
		$t->same( 1, count( epm_i_requested( '/imp/cycle/p2.xml' ) ), 'each page once' );

		epm_i_reset();
		$pages = epm_i_pages( 'dupes' );
		$p2    = 'https://feeds.example.test/imp/dupes/p2.xml';
		$pages[ $p2 ] = preg_replace( '/<item>/', epm_i_item( 'imp-dupes-007', gmmktime( 8, 0, 0, 1, 1, 2025 ), '<p>OLDER COPY</p>' ) . '<item>', $pages[ $p2 ], 1 );
		$p = epm_i_preview( epm_i_serve( $pages ) );
		$t->same( [ true, 9, [ 'Episode imp-dupes-007' ] ], [ $p['catalog']['complete'] ?? null, $p['episodes'] ?? null, $p['duplicates'] ?? null ], 'duplicates across pages' );
		$state = epm_i_run( $p, [ 'purpose' => 'mirror' ] );
		$t->same( 'done', $state['status'] ?? '' );
		$t->same( 9, count( epm_i_guids( 'imp-dupes-' ) ), 'nine episodes' );
		$t->same( 1, max( epm_i_guids( 'imp-dupes-' ) ), 'one each' );
		$t->assert( false === strpos( (string) get_post_field( 'post_content', Importer::find_guid( 'imp-dupes-007' ) ), 'OLDER COPY' ), 'the first page\'s copy wins' );
		epm_i_delete( 'imp-dupes-' );
	}
);

$t->test(
	'after a failed page, "try again" continues from that page: no page read twice, no episode twice',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$pages = epm_i_pages( 'resume' );
		$p2    = 'https://feeds.example.test/imp/resume/p2.xml';
		$p     = epm_i_preview( epm_i_serve( $pages, [ $p2 => EPM_Test_HTTP::response( 503, 'Busy' ) ] ) );
		$t->same( [ false, 3 ], [ $p['catalog']['complete'] ?? null, $p['episodes'] ?? null ], 'first try' );

		epm_i_route( $p2, $pages[ $p2 ] );
		EPM_Test_HTTP::$log = [];
		// (1.3.0 had no way to continue: the feed was checked again.)
		$p = is_callable( [ ImportJob::class, 'preview_more' ] ) ? ImportJob::preview_more( (string) ( $p['token'] ?? '' ) ) : ImportJob::preview( (string) array_key_first( $pages ) );
		for ( $i = 0; $i < 20 && ! is_wp_error( $p ) && ! empty( $p['catalog']['loading'] ); $i++ ) {
			$p = ImportJob::preview_more( (string) $p['token'] );
		}
		$t->same( [ true, 9 ], [ $p['catalog']['complete'] ?? null, $p['episodes'] ?? null ], 'after the retry' );
		$t->same( [], epm_i_requested( '/imp/resume/p1.xml' ), 'page 1 is not read again' );
		$state = epm_i_run( $p, [ 'purpose' => 'mirror' ] );
		$t->same( [ 'done', 9 ], [ $state['status'] ?? '', (int) ( $state['counts']['created'] ?? 0 ) ] );
		$t->same( 9, count( epm_i_guids( 'imp-resume-' ) ) );
		$t->same( 1, max( epm_i_guids( 'imp-resume-' ) ), 'one each' );
		epm_i_delete( 'imp-resume-' );
	}
);

$t->test(
	'a move with an incomplete catalog never finishes the move unless the missing part is accepted explicitly',
	static function ( EPM_Test_Runner $t ) {
		$pages = epm_i_pages( 'move' );
		$p2    = 'https://feeds.example.test/imp/move/p2.xml';
		$url   = epm_i_serve( $pages, [ $p2 => EPM_Test_HTTP::response( 500, 'err' ) ] );
		epm_i_reset(
			[
				'mode'     => 'external',
				'feed_url' => $url,
				'sync'     => false,
			]
		);
		$p     = epm_i_preview( $url );
		$state = ImportJob::start(
			(string) $p['token'],
			[
				'purpose'       => 'move',
				'confirm_owner' => true,
			]
		);
		for ( $i = 0; $i < 50 && ! is_wp_error( $state ) && 'running' === $state['status']; $i++ ) {
			$state = ImportJob::step( 8.0 );
		}
		$t->same( 'epm_import_incomplete', is_wp_error( $state ) ? $state->get_error_code() : 'started', 'refused' );
		$t->same( 'ready', ImportJob::get()['status'] ?? '', 'the job waits' );
		$t->same( [], epm_i_guids( 'imp-move-' ), 'nothing imported' );
		$t->same( [ false, false, 'external' ], [ (bool) epm()->settings->get( 'moved_in' ), (bool) epm()->settings->get( 'locked' ), Hosting::get( 'mode' ) ], 'not moved, not locked, still mirroring' );

		// Mirroring what was found is allowed (the sync keeps it up to date).
		$state = epm_i_run( $p, [ 'purpose' => 'mirror' ] );
		$t->same( 'done', $state['status'] ?? '', 'mirror' );
		$t->same( false, $state['catalog']['complete'] ?? null, 'the result still says the catalog is incomplete' );
		$t->same( [ false, 'external' ], [ (bool) epm()->settings->get( 'moved_in' ), Hosting::get( 'mode' ) ], 'a mirror never finishes a move' );
		epm_i_delete( 'imp-move-' );

		// The explicit, informed choice: move only what was found.
		$p     = epm_i_preview( $url );
		$state = epm_i_run(
			$p,
			[
				'purpose'        => 'move',
				'confirm_owner'  => true,
				'accept_partial' => true,
			]
		);
		$t->same( 'done', is_wp_error( $state ) ? $state->get_error_message() : $state['status'], 'accepted' );
		$t->same( 3, count( epm_i_guids( 'imp-move-' ) ) );
		$t->same( [ true, 'self' ], [ (bool) epm()->settings->get( 'moved_in' ), Hosting::get( 'mode' ) ], 'moved as confirmed' );
		epm_i_delete( 'imp-move-' );
		epm_i_reset();
	}
);

$t->test(
	'wp podcast import: a partial catalog is an error with a way forward; --accept-partial imports it with a warning; --resume continues',
	static function ( EPM_Test_Runner $t ) {
		$pages = epm_i_pages( 'cli' );
		$p2    = 'https://feeds.example.test/imp/cli/p2.xml';
		$url   = epm_i_serve( $pages, [ $p2 => EPM_Test_HTTP::response( 500, 'err' ) ] );
		epm_i_reset();
		$cli = static function ( string $command ) {
			return WP_CLI::runcommand(
				$command,
				[
					'return'     => 'all',
					'launch'     => false,
					'exit_error' => false,
				]
			);
		};

		$run = $cli( 'podcast import ' . $url . ' --move --owner' );
		$t->same( 1, $run->return_code, 'exit code' );
		$t->assert( false !== stripos( $run->stderr, 'page 2' ) && false !== strpos( $run->stderr, '500' ), 'names the page and the error: ' . $run->stderr );
		$t->assert( false !== strpos( $run->stderr, '--resume' ) && false !== strpos( $run->stderr, '--accept-partial' ), 'and the way forward' );
		$t->same( [], epm_i_guids( 'imp-cli-' ), 'nothing imported' );
		$t->same( false, (bool) epm()->settings->get( 'moved_in' ), 'not moved' );

		// The host is back: --resume reads the rest and imports. (An
		// unknown flag would end this process, so check the synopsis.)
		$help = ( new ReflectionMethod( \EPM\Cli::class, 'import' ) )->getDocComment();
		$t->assert( false !== strpos( (string) $help, '--resume' ) && false !== strpos( (string) $help, '--accept-partial' ), 'the command has --resume and --accept-partial' );
		if ( false === strpos( (string) $help, '--resume' ) ) {
			epm_i_delete( 'imp-cli-' );
			epm_i_reset();
			return;
		}
		epm_i_route( $p2, $pages[ $p2 ] );
		$run = $cli( 'podcast import --resume --move --owner' );
		$t->same( 0, $run->return_code, 'resumed: ' . $run->stderr );
		$t->same( 9, count( epm_i_guids( 'imp-cli-' ) ), 'every episode' );
		$t->assert( false !== strpos( $run->stdout, 'Success' ), 'success' );
		epm_i_delete( 'imp-cli-' );
		epm_i_reset();

		epm_i_route( $p2, EPM_Test_HTTP::response( 500, 'err' ) );
		$run = $cli( 'podcast import ' . $url . ' --accept-partial' );
		$t->same( 0, $run->return_code, 'accepted' );
		$t->same( 3, count( epm_i_guids( 'imp-cli-' ) ) );
		$t->assert( false !== strpos( $run->stderr, 'Warning' ) && false !== stripos( $run->stderr, 'incomplete' ), 'warns that the catalog is incomplete: ' . $run->stderr );
		$t->assert( false === strpos( $run->stdout, 'Success' ), 'no plain success' );
		epm_i_delete( 'imp-cli-' );
		epm_i_reset();
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Where the parsed feed is kept' );
/* ------------------------------------------------------------------------- */

$t->test(
	'the parsed feed is kept in the database, not in the uploads folder, and an import reads it back intact',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$url   = 'https://feeds.example.test/imp/store/feed.xml';
		$items = [];
		// About 1.5 MB of show notes: several storage rows.
		for ( $k = 30; $k >= 1; $k-- ) {
			$notes   = '<p>Ünïcödé “quotes” &amp; <a href="https://example.com/?a=1&amp;b=2">links</a> – ' . $k . '</p>' . str_repeat( '<p>' . str_repeat( 'Lorem ipsum dolor sit amet. ', 18 ) . '</p>', 100 );
			$items[] = epm_i_item( 'imp-store-' . $k, gmmktime( 8, 0, 0, 1, $k, 2025 ), $notes );
		}
		epm_i_route( $url, epm_i_page( 'Store show', $items ) );
		$p = epm_i_preview( $url );
		$t->same( 30, $p['episodes'] ?? null );
		$t->same( [], epm_i_uploads_containing( 'imp-store-17' ), 'nothing in the uploads folder' );
		$t->same( false, is_dir( wp_upload_dir( null, false )['basedir'] . '/epm-import' ), 'no import folder' );
		$t->same( 1, count( epm_i_stored() ), 'one stored job' );

		$state = epm_i_run( $p, [ 'purpose' => 'mirror' ] );
		$t->same( [ 'done', 30 ], [ $state['status'] ?? '', (int) ( $state['counts']['created'] ?? 0 ) ] );
		$first = Importer::find_guid( 'imp-store-1' );
		$last  = Importer::find_guid( 'imp-store-30' );
		$t->assert( $first > 0 && $first < $last, 'created oldest first' );
		$t->assert( false !== strpos( (string) get_post_field( 'post_content', Importer::find_guid( 'imp-store-17' ) ), 'Ünïcödé “quotes” &amp; <a href="https://example.com/?a=1&amp;b=2">links</a> – 17' ), 'notes intact' );
		$t->same( [], epm_i_stored(), 'removed when the import is done' );
		epm_i_delete( 'imp-store-' );
	}
);

$t->test(
	'stored feed data is removed on cancel, on a new preview and on a failed import; two previews that overlap leave one job',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$a = epm_i_serve( epm_i_pages( 'keep-a', 2, 2 ) );
		$b = epm_i_serve( epm_i_pages( 'keep-b', 1, 2 ) );

		epm_i_preview( $a );
		ImportJob::cancel();
		$t->same( [], epm_i_stored(), 'cancel' );

		epm_i_preview( $a );
		$second = epm_i_preview( $b );
		$t->same( 1, count( epm_i_stored() ), 'a new preview replaces the old data' );

		// The stored data disappears while the import runs: the job fails
		// and nothing is left behind.
		ImportJob::start( (string) $second['token'], [ 'purpose' => 'mirror' ] );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'epm_import_chunk_' ) . '%' ) );
		$state = ImportJob::step( 8.0 );
		$t->same( 'failed', $state['status'], 'failed' );
		$t->assert( '' !== $state['error'], 'with a message' );
		$t->same( [], epm_i_stored(), 'nothing left' );
		epm_i_delete( 'imp-keep-' );

		// Another preview finishes while this one still reads its second page.
		epm_i_reset();
		$pages = epm_i_pages( 'keep-a', 2, 2 );
		$p2    = 'https://feeds.example.test/imp/keep-a/p2.xml';
		$other = null;
		epm_i_route(
			$p2,
			static function () use ( $pages, $p2, $b, &$other ) {
				$other = ImportJob::preview( $b );
				return EPM_Test_HTTP::response( 200, $pages[ $p2 ], [ 'content-type' => 'application/rss+xml' ] );
			}
		);
		$first = epm_i_preview( $a );
		$job   = ImportJob::get();
		$t->assert( is_array( $other ) && ( $job['token'] ?? '' ) === ( $other['token'] ?? '' ), 'the preview that finished last is the job' );
		$t->same( 'epm_import_replaced', is_wp_error( $first ) ? $first->get_error_code() : 'a summary', 'the replaced preview says so' );
		$t->same( 1, count( epm_i_stored() ), 'exactly one stored job' );
		$t->same( [], glob( wp_upload_dir( null, false )['basedir'] . '/epm-import/job-*' ) ?: [], 'no job files' );
		ImportJob::cancel();
	}
);

$t->test(
	'a checked feed that is never imported expires after a day, with its data',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$p = epm_i_preview( epm_i_serve( epm_i_pages( 'ttl', 1, 2 ) ) );
		$t->same( 1, count( epm_i_stored() ) );
		$t->assert( false !== wp_next_scheduled( 'epm_import_cleanup' ), 'a cleanup is scheduled' );

		// 25 hours later.
		global $wpdb;
		$job            = ImportJob::get();
		$job['created'] = time() - 25 * HOUR_IN_SECONDS;
		$job['touched'] = time() - 25 * HOUR_IN_SECONDS;
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $job ) ], [ 'option_name' => ImportJob::OPTION ] );
		do_action( 'epm_import_cleanup' );

		$t->same( [], epm_i_stored(), 'data removed' );
		$started = ImportJob::start( (string) $p['token'], [ 'purpose' => 'mirror' ] );
		$t->same( 'epm_import_token', is_wp_error( $started ) ? $started->get_error_code() : 'started', 'the import expired' );

		// A day-old running import is not touched.
		$p = epm_i_preview( epm_i_serve( epm_i_pages( 'ttl', 1, 2 ) ) );
		ImportJob::start( (string) $p['token'], [ 'purpose' => 'mirror' ] );
		$job            = ImportJob::get();
		$job['created'] = time() - 25 * HOUR_IN_SECONDS;
		$job['touched'] = time() - 25 * HOUR_IN_SECONDS;
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $job ) ], [ 'option_name' => ImportJob::OPTION ] );
		do_action( 'epm_import_cleanup' );
		$t->same( [ 'running', 1 ], [ ImportJob::get()['status'] ?? '', count( epm_i_stored() ) ], 'a running import keeps its data' );
		ImportJob::cancel();
		wp_clear_scheduled_hook( 'epm_import_cleanup' );
	}
);

$t->test(
	'a 1.3.0 import file in uploads/epm-import: a running import continues from the database and the folder is removed',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		$dir = wp_upload_dir( null, false )['basedir'] . '/epm-import/';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . 'job-OrphanedOldPreview0000.json', '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$parsed = ( new \EPM\FeedParser() )->parse( epm_i_page( 'Legacy show', [ epm_i_item( 'imp-legacy-2', gmmktime( 8, 0, 0, 1, 2, 2025 ) ), epm_i_item( 'imp-legacy-1', gmmktime( 8, 0, 0, 1, 1, 2025 ) ) ] ) );
		$file   = $dir . 'job-LegacyRunningImport0000.json';
		file_put_contents( $file, (string) wp_json_encode( array_reverse( $parsed['items'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_option(
			ImportJob::OPTION,
			[
				'token'    => 'LegacyRunningImport0000',
				'status'   => 'running',
				'feed_url' => 'https://feeds.example.test/imp/legacy.xml',
				'file'     => $file,
				'channel'  => $parsed['channel'],
				'total'    => 2,
				'position' => 0,
				'counts'   => [],
				'log'      => [],
				'created'  => time(),
				'options'  => [
					'status'         => 'publish',
					'download_media' => false,
					'purpose'        => 'mirror',
				],
				'started'  => time(),
				'touched'  => time(),
			],
			false
		);
		$state = ImportJob::step( 8.0 );
		$t->same( [ 'done', 2 ], [ $state['status'], (int) ( $state['counts']['created'] ?? 0 ) ], 'the 1.3.0 import finishes' );
		$t->same( false, file_exists( $file ), 'its file is gone' );
		do_action( 'epm_import_cleanup' );
		$t->same( false, is_dir( $dir ), 'and the folder, with the orphaned file' );
		epm_i_delete( 'imp-legacy-' );
	}
);

$t->test(
	'uninstalling removes the stored feed data, the import state, the 1.3.0 folder and the cleanup event',
	static function ( EPM_Test_Runner $t ) {
		epm_i_reset();
		epm_i_preview( epm_i_serve( epm_i_pages( 'uninstall', 2, 2 ) ) );
		$dir = wp_upload_dir( null, false )['basedir'] . '/epm-import/';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . 'job-Old.json', '[]' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$t->assert( count( epm_i_stored() ) > 0, 'data stored' );

		$keep = static function () {
			return false;
		};
		add_filter( 'epm_delete_data_on_uninstall', $keep, 99 );
		try {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'elementor-podcast-manager/elementor-podcast-manager.php' );
			}
			include dirname( __DIR__, 2 ) . '/uninstall.php';
		} finally {
			remove_filter( 'epm_delete_data_on_uninstall', $keep, 99 );
		}
		$t->same( [], epm_i_stored(), 'no stored feed data' );
		$t->same( '__absent__', get_option( ImportJob::OPTION, '__absent__' ), 'no job' );
		$t->same( false, is_dir( $dir ), 'no 1.3.0 folder' );
		$t->same( false, wp_next_scheduled( 'epm_import_cleanup' ), 'no cleanup event' );
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
