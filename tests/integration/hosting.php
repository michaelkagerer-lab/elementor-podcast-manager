<?php
/**
 * Integration tests for hosting modes, feed import, host sync, feed output
 * for moved and measured shows, transcript files, the setup steps and
 * distribution progress, executed inside WordPress after the fixtures are
 * seeded (tests/fixtures/seed.php):
 *
 *   wp eval-file tests/integration/hosting.php
 *
 * Feeds are served by the fixture HTTP server
 * (tests/fixtures/mu-plugins/epm-test-http.php) at https://feeds.example.test/:
 * the real feeds in tests/fixtures/feeds/ and the synthetic ones in
 * tests/fixtures/feeds/synthetic/. Every other outgoing request is refused,
 * so the run is offline and repeatable.
 *
 * The site is left as it was found: posts created here are deleted and the
 * options changed here are restored at the end.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\AdminPages;
use EPM\AudioMetadata;
use EPM\Directories;
use EPM\EpisodePostType;
use EPM\Episodes;
use EPM\Feed;
use EPM\FeedParser;
use EPM\Hosting;
use EPM\ImportJob;
use EPM\Importer;
use EPM\PodcastSettings;
use EPM\Providers;
use EPM\Transcripts;

require_once __DIR__ . '/lib.php';
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

$GLOBALS['epm_test_doing_it_wrong'] = [];
add_action(
	'doing_it_wrong_run',
	static function ( $function, $message ) {
		$GLOBALS['epm_test_doing_it_wrong'][] = $function . ': ' . wp_strip_all_tags( (string) $message );
	},
	10,
	2
);

EPM_Test_HTTP::$offline = true;
EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$log     = [];

// What the run changes, to put back at the end.
$GLOBALS['epm_h_max_id']  = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts}" );
$GLOBALS['epm_h_options'] = [];
foreach ( [ PodcastSettings::OPTION, Hosting::OPTION, Hosting::STATE_OPTION, ImportJob::OPTION, 'epm_import_lock', AdminPages::SETUP_OPTION, Directories::OPTION, Feed::GUID_OPTION, Feed::BUILD_OPTION, 'epm_design_settings' ] as $epm_h_name ) {
	$GLOBALS['epm_h_options'][ $epm_h_name ] = epm_test_option_snapshot( $epm_h_name );
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------- */

/**
 * Fixture URL.
 *
 * @param string $path Path below https://feeds.example.test/.
 * @return string
 */
function epm_h_url( string $path ): string {
	return 'https://feeds.example.test/' . ltrim( $path, '/' );
}

/**
 * Fixture file contents.
 *
 * @param string $name Path below tests/fixtures/feeds/.
 * @return string
 */
function epm_h_file( string $name ): string {
	return (string) file_get_contents( EPM_Test_HTTP::dir() . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * Parse a fixture file.
 *
 * @param string $name Path below tests/fixtures/feeds/.
 * @return array|WP_Error
 */
function epm_h_parse( string $name ) {
	return ( new FeedParser() )->parse( epm_h_file( $name ) );
}

/**
 * Put an option back as it was before the run.
 *
 * @param string $name Option.
 * @return void
 */
function epm_h_restore( string $name ): void {
	epm_test_option_restore( $name, $GLOBALS['epm_h_options'][ $name ] ?? null );
}

/**
 * Change the hosting settings (through the sanitizer, like the form).
 *
 * @param array $changes Values.
 * @return void
 */
function epm_h_hosting( array $changes ): void {
	update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), $changes ) ) );
}

/**
 * Episode IDs with a GUID (any status, trash included).
 *
 * @param string $guid GUID.
 * @return int[]
 */
function epm_h_ids( string $guid ): array {
	global $wpdb;

	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_epm_guid' AND pm.meta_value = %s AND p.post_type = 'podcast_episode' ORDER BY pm.post_id",
				$guid
			)
		)
	);
}

/**
 * The one episode with a GUID (0 when none).
 *
 * @param string $guid GUID.
 * @return int
 */
function epm_h_id( string $guid ): int {
	$ids = epm_h_ids( $guid );

	return $ids[0] ?? 0;
}

/**
 * Episodes imported from a feed (any status but trash).
 *
 * @param string $feed_url Source feed.
 * @param string $status   Post status or 'any'.
 * @return int[]
 */
function epm_h_from_feed( string $feed_url, string $status = 'any' ): array {
	return array_map(
		'intval',
		get_posts(
			[
				'post_type'      => 'podcast_episode',
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_epm_source_feed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $feed_url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		)
	);
}

/**
 * Preview, start and step an import until it finishes.
 *
 * @param string $url     Feed URL.
 * @param array  $options Options for ImportJob::start().
 * @return array{preview: array, state: array}|WP_Error
 */
function epm_h_import( string $url, array $options ) {
	$preview = ImportJob::preview( $url );
	if ( is_wp_error( $preview ) ) {
		return $preview;
	}
	$state = ImportJob::start( (string) $preview['token'], $options );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	for ( $i = 0; $i < 200 && 'running' === $state['status']; $i++ ) {
		$state = ImportJob::step( 8.0 );
	}

	return [
		'preview' => $preview,
		'state'   => $state,
	];
}

/**
 * Requests made to a URL since the log was cleared.
 *
 * @param string $url URL.
 * @return array[]
 */
function epm_h_requests( string $url ): array {
	return array_values(
		array_filter(
			EPM_Test_HTTP::$log,
			static function ( $entry ) use ( $url ) {
				return $entry['url'] === $url;
			}
		)
	);
}

/**
 * The feed item with a GUID.
 *
 * @param DOMXPath $xp   Feed.
 * @param string   $guid GUID.
 * @return DOMElement|null
 */
function epm_h_item( DOMXPath $xp, string $guid ): ?DOMElement {
	foreach ( $xp->query( '/rss/channel/item' ) as $item ) {
		$node = $xp->query( 'guid', $item )->item( 0 );
		if ( $node && $node->textContent === $guid ) {
			return $item;
		}
	}

	return null;
}

/**
 * The podcast:transcript tags of a feed item, in order: [ url, type, rel ].
 *
 * @param DOMXPath   $xp   Feed.
 * @param DOMElement $item Item.
 * @return array[]
 */
function epm_h_transcripts( DOMXPath $xp, DOMElement $item ): array {
	$out = [];
	foreach ( $xp->query( 'podcast:transcript', $item ) as $node ) {
		$out[] = [ $node->getAttribute( 'url' ), $node->getAttribute( 'type' ), $node->getAttribute( 'rel' ) ];
	}

	return $out;
}

/**
 * Add a file to the Media Library without an upload (the file is written
 * to the uploads folder directly, so no user and no upload type check is
 * involved).
 *
 * @param string $name File name.
 * @param string $body Contents.
 * @param string $mime MIME type.
 * @return int Attachment ID.
 */
function epm_h_attachment( string $name, string $body, string $mime ): int {
	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $name );
	file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	return (int) wp_insert_attachment(
		[
			'post_mime_type' => $mime,
			'post_title'     => $name,
			'post_status'    => 'inherit',
		],
		$path
	);
}

/**
 * A host feed for the sync tests: episodes "sync-N" dated 1–N May 2026,
 * newest first.
 *
 * @param int[]  $numbers       Episode numbers in the feed.
 * @param array  $changes       Per number: title, notes, duration.
 * @param string $channel_extra Extra channel XML.
 * @return string
 */
function epm_h_sync_feed( array $numbers, array $changes = [], string $channel_extra = '' ): string {
	rsort( $numbers );
	$items = '';
	foreach ( $numbers as $n ) {
		$c      = $changes[ $n ] ?? [];
		$items .= sprintf(
			"\n<item><title>%s</title><guid isPermaLink=\"false\">sync-%d</guid><pubDate>%s</pubDate><description>%s</description><enclosure url=\"https://feeds.example.test/media/sync-%d.mp3\" length=\"%d\" type=\"audio/mpeg\"/><itunes:duration>%d</itunes:duration></item>",
			esc_xml( $c['title'] ?? 'Sync episode ' . $n ),
			$n,
			gmdate( DATE_RSS, gmmktime( 8, 0, 0, 5, $n, 2026 ) ),
			esc_xml( $c['notes'] ?? 'Notes for episode ' . $n . '.' ),
			$n,
			1000 + $n,
			$c['duration'] ?? 600 + $n
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel>'
		. '<title>Sync Show</title><link>https://show.example.test/sync/</link><description>Published at a host.</description>'
		. $channel_extra . $items . "\n</channel></rss>\n";
}

$t = new EPM_Test_Runner();

$t->test(
	'QA-01: fixture restoration keeps serialized values and the original autoload policy',
	static function ( EPM_Test_Runner $t ) {
		$name = 'epm_test_restore_' . wp_generate_uuid4();
		$value = [ 'local' => [ 'GUID & quotes “kept”', false, 1 ] ];
		try {
			add_option( $name, $value, '', false );
			$snapshot = epm_test_option_snapshot( $name );
			delete_option( $name );
			add_option( $name, 'changed', '', true );
			epm_test_option_restore( $name, $snapshot );
			$t->same( $snapshot, epm_test_option_snapshot( $name ), 'raw serialization and autoload are identical' );
			$t->same( $value, get_option( $name ), 'the original value is readable' );
			epm_test_option_restore( $name, null );
			$t->same( false, get_option( $name ), 'absent options and their caches are restored' );
		} finally {
			epm_test_option_restore( $name, null );
		}
	}
);

$t->test(
	'SEC-N9: headless imports have a real author and updates preserve local attribution',
	static function ( EPM_Test_Runner $t ) {
		$user = get_current_user_id();
		$id = 0;
		try {
			wp_set_current_user( 0 );
			$item = ( new FeedParser() )->parse( epm_h_sync_feed( [ 1 ] ) )['items'][0];
			$item['guid'] = 'headless-author-' . wp_generate_uuid4();
			$importer = new Importer( [ 'fetch_extras' => false ] );
			$outcome = $importer->import_item( $item );
			$id = (int) $outcome['id'];
			$author = (int) get_post_field( 'post_author', $id );
			$t->assert( $author > 0 && false !== get_user_by( 'id', $author ), 'cron and CLI imports with no current user receive an existing author' );
			wp_update_post( [ 'ID' => $id, 'post_author' => $user ] );
			$item['title'] .= ' updated';
			$importer->import_item( $item );
			$t->same( $user, (int) get_post_field( 'post_author', $id ), 'updating an imported episode preserves its local author' );
		} finally {
			wp_set_current_user( $user );
			if ( $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

$t->test(
	'PERF-N3: syncing a short feed never primes transcript metadata for the whole catalog',
	static function ( EPM_Test_Runner $t ) {
		$hosting = get_option( Hosting::OPTION, false );
		$state = get_option( Hosting::STATE_OPTION, false );
		$ids = [];
		$max_batch = 0;
		$observe = static function ( $pre, $object_ids ) use ( &$max_batch ) {
			$max_batch = max( $max_batch, count( $object_ids ) );
			return $pre;
		};
		try {
			for ( $i = 0; $i < 60; ++$i ) {
				$id = wp_insert_post( [ 'post_type' => EpisodePostType::CPT, 'post_status' => 'publish', 'post_title' => 'Transcript cache probe ' . $i ] );
				update_post_meta( $id, '_epm_guid', 'cache-probe-' . wp_generate_uuid4() );
				update_post_meta( $id, '_epm_transcript', str_repeat( 'Long transcript. ', 4096 ) );
				wp_cache_delete( $id, 'post_meta' );
				wp_cache_delete( $id, 'posts' );
				$ids[] = $id;
			}
			epm_h_hosting( [ 'mode' => 'external', 'feed_url' => epm_h_url( 'synthetic/paged-2.xml' ) ] );
			delete_option( Hosting::STATE_OPTION );
			add_filter( 'update_post_metadata_cache', $observe, 10, 2 );
			$result = Hosting::sync( true );
			remove_filter( 'update_post_metadata_cache', $observe, 10 );
			$t->same( 'ok', $result['status'], 'the short feed synchronizes' );
			$t->assert( $max_batch <= 25, 'metadata remains bounded to a batch, not the catalog (' . $max_batch . ' IDs)' );
		} finally {
			remove_filter( 'update_post_metadata_cache', $observe, 10 );
			foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
			foreach ( [ Hosting::OPTION => $hosting, Hosting::STATE_OPTION => $state ] as $option => $value ) {
				if ( false === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
			}
		}
	}
);

$t->test(
	'SYNC-N4: stale scheduled syncs show a warning instead of an In sync badge',
	static function ( EPM_Test_Runner $t ) {
		try {
			epm_h_hosting( [ 'mode' => 'external', 'feed_url' => epm_h_url( 'stale.xml' ), 'sync' => true, 'interval' => 'hourly' ] );
			Hosting::update_state( [ 'status' => 'ok', 'last_success' => time() - WEEK_IN_SECONDS, 'last_run' => time() - WEEK_IN_SECONDS ] );
			$report = \EPM\Readiness::report();
			$warnings = array_filter( $report['checks'], static fn( $check ) => 'warning' === $check['status'] && false !== stripos( $check['message'], 'overdue' ) );
			$t->assert( count( $warnings ) > 0, 'overdue sync is explained' );
			$html = \EPM\Readiness::render_html( $report );
			$t->assert( ! preg_match( '/epm-badge[^>]*>.*?In sync<\/span>/s', $html ), 'the summary does not claim a stale sync is healthy' );
			$t->assert( false !== strpos( $html, 'Sync overdue' ), 'the summary names the overdue sync' );
		} finally {
			epm_h_restore( Hosting::OPTION );
			epm_h_restore( Hosting::STATE_OPTION );
		}
	}
);

$t->test(
	'SYNC-N6: a paged sync names its scope and never drafts episodes from unexamined pages',
	static function ( EPM_Test_Runner $t ) {
		$url = epm_h_url( 'synthetic/paged-1.xml' );
		$hosting = get_option( Hosting::OPTION, false );
		$state = get_option( Hosting::STATE_OPTION, false );
		try {
			$item = epm_h_parse( 'synthetic/paged-2.xml' )['items'][0];
			$item['guid'] = 'unexamined-page-' . wp_generate_uuid4();
			$item['pub_date'] = strtotime( '2026-06-05 12:00:00 UTC' );
			$older = ( new Importer( [ 'feed_url' => $url ] ) )->import_item( $item );
			update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), [ 'mode' => 'external', 'feed_url' => $url, 'missing' => 'draft' ] ) ) );
			delete_option( Hosting::STATE_OPTION );
			$result = Hosting::sync( true );
			$t->assert( false !== stripos( $result['message'], 'first page' ), 'the sync message states its paging limit' );
			$t->same( 'publish', get_post_status( $older['id'] ), 'episodes outside the examined page retain their status' );
		} finally {
			foreach ( [ Hosting::OPTION => $hosting, Hosting::STATE_OPTION => $state ] as $option => $value ) {
				if ( false === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
			}
		}
	}
);

$t->test(
	'SEC-N7: secure feed redirects are stopped before an insecure hop',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ [ 'https', 'http', true ], [ 'https', 'https', false ], [ 'http', 'http', false ] ] as $case ) {
			[ $from, $to, $blocked ] = $case;
			$url = $from . '://feeds.example.test/redirect-security.xml';
			$requested_target = false;
			EPM_Test_HTTP::$routes[ $url ] = static function () use ( $url, $to, &$requested_target ) {
				$target = $to . '://cdn.example.test/secure-feed.xml';
				// Inject the same transport hook Requests fires before fetching a hop.
				do_action( 'requests-requests.before_redirect', $target, [], [], [], (object) [ 'url' => $url ] );
				$requested_target = true;
				return EPM_Test_HTTP::response( 200, epm_h_file( 'synthetic/paged-2.xml' ) );
			};
			try {
				$response = Hosting::fetch( $url );
				$t->same( $blocked, is_wp_error( $response ), $from . ' to ' . $to . ': result' );
				$t->same( ! $blocked, $requested_target, 'the downgrade never reaches its target' );
				if ( $blocked ) {
					$t->same( 'epm_insecure_redirect', $response instanceof WP_Error ? $response->get_error_code() : '', 'specific HTTPS downgrade error' );
					$media = EPM\MediaDownload::run( EPM\MediaDownload::fresh( $url, 'audio' ), microtime( true ) + 10 );
					$t->same( 'unsafe', $media['reason'] ?? '', 'media downloads reject the insecure hop too' );
					$t->same( false, $requested_target, 'media never reaches the insecure target' );
				}
			} finally {
				unset( EPM_Test_HTTP::$routes[ $url ] );
			}
		}
		$t->assert( ! has_action( 'requests-requests.before_redirect' ), 'the request guard is removed after success and failure' );
	}
);

$t->test(
	'SEC-N8: oversized remote feeds and extras are rejected with useful item messages',
	static function ( EPM_Test_Runner $t ) {
		$url = epm_h_url( 'size-limit.xml' );
		EPM_Test_HTTP::$routes[ $url ] = static function () { return EPM_Test_HTTP::response( 200, str_repeat( 'x', 65 ), [ 'content-length' => '65' ] ); };
		$limit = static function () { return 64; };
		add_filter( 'epm_feed_max_bytes', $limit );
		try {
			$response = Hosting::fetch( $url );
			$t->same( 'epm_feed_too_large', $response instanceof WP_Error ? $response->get_error_code() : '', 'feed reports its limit rather than an XML parse error' );
		} finally {
			remove_filter( 'epm_feed_max_bytes', $limit );
			unset( EPM_Test_HTTP::$routes[ $url ] );
		}
		$chapter_url = epm_h_url( 'oversized-chapters.json' );
		$transcript_url = epm_h_url( 'oversized-transcript.txt' );
		EPM_Test_HTTP::$routes[ $chapter_url ] = static function () { return EPM_Test_HTTP::response( 200, '{"chapters":[{"startTime":0,"title":"Truncated"}]}', [ 'content-length' => (string) ( 512 * KB_IN_BYTES + 1 ) ] ); };
		EPM_Test_HTTP::$routes[ $transcript_url ] = static function () { return EPM_Test_HTTP::response( 200, 'Incomplete transcript', [ 'content-length' => (string) ( 2 * MB_IN_BYTES + 1 ) ] ); };
		try {
			$item = epm_h_parse( 'synthetic/paged-2.xml' )['items'][0];
			$item['guid'] = 'size-limit-extras-' . wp_generate_uuid4();
			$item['chapters'] = [];
			$item['chapters_url'] = $chapter_url;
			$item['transcripts'] = [ [ 'url' => $transcript_url, 'type' => 'text/plain' ] ];
			$result = ( new Importer() )->import_item( $item );
			$t->same( '', get_post_meta( $result['id'], '_epm_chapters', true ), 'no truncated chapters are stored' );
			$t->same( '', get_post_meta( $result['id'], '_epm_transcript', true ), 'no truncated transcript is stored' );
			$t->assert( false !== stripos( $result['message'], 'chapters' ) && false !== stripos( $result['message'], 'transcript' ) && false !== stripos( $result['message'], 'limit' ), 'both skipped documents are explained in the item log' );
		} finally {
			unset( EPM_Test_HTTP::$routes[ $chapter_url ], EPM_Test_HTTP::$routes[ $transcript_url ] );
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Feed parser: real feeds' );
/* ------------------------------------------------------------------------- */

$epm_h_real = [ 'acast', 'alitu', 'art19', 'audioboom', 'bbc-selfhosted', 'blubrry', 'buzzsprout', 'captivate', 'castos', 'libsyn', 'megaphone', 'omny', 'podbean', 'podigee', 'podigee-2', 'redcircle', 'riverside', 'rss-com', 'simplecast', 'soundcloud', 'spotify-for-creators', 'spreaker', 'transistor', 'wordpress-powerpress', 'wordpress-ssp', 'wordpress-ssp-2' ];

$t->test(
	'every real feed parses: show title and artwork, three items with GUID, audio, date and a trimmed title',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_real ) {
		$files = array_map(
			static function ( $path ) {
				return basename( $path, '.xml' );
			},
			glob( EPM_Test_HTTP::dir() . '*.xml' )
		);
		sort( $files );
		$t->same( $epm_h_real, $files, 'fixture set' );

		foreach ( $epm_h_real as $name ) {
			$feed = epm_h_parse( $name . '.xml' );
			if ( is_wp_error( $feed ) ) {
				$t->assert( false, $name . ': ' . $feed->get_error_message() );
				continue;
			}
			$t->assert( '' !== $feed['channel']['title'], $name . ' title' );
			$t->assert( (bool) preg_match( '#^https?://#', $feed['channel']['image'] ), $name . ' artwork: ' . $feed['channel']['image'] );
			$t->same( 3, count( $feed['items'] ), $name . ' items' );
			foreach ( $feed['items'] as $i => $item ) {
				$label = $name . ' item ' . $i;
				$t->assert( '' !== $item['guid'] && trim( $item['guid'] ) === $item['guid'], $label . ' guid' );
				$t->assert( (bool) preg_match( '#^https?://\S+$#', $item['audio_url'] ), $label . ' audio URL: ' . $item['audio_url'] );
				$t->assert( false !== strpos( $item['audio_type'], '/' ), $label . ' audio type' );
				$t->assert( $item['pub_date'] > 0, $label . ' date' );
				$t->assert( '' !== $item['title'] && trim( $item['title'] ) === $item['title'] && false === strpos( $item['title'], "\n" ), $label . ' title ' . var_export( $item['title'], true ) );
			}
		}
	}
);

$t->test(
	'Podigee: chapters linked with href= instead of url=, plus inline Podlove chapters',
	static function ( EPM_Test_Runner $t ) {
		$item = epm_h_parse( 'podigee.xml' )['items'][0];
		$t->same( 'https://podcast-news.podigee.io/14-wie-alles-begann/chapters.json', $item['chapters_url'] );
		$t->same( 'application/json+chapters', $item['chapters_type'] );
		$t->same( 16, count( $item['chapters'] ), 'inline psc:chapters' );
		$t->same( 0, $item['chapters'][0]['start'] );
		$t->assert( $item['chapters'][1]['start'] > 0 && '' !== $item['chapters'][1]['title'], 'second chapter' );
	}
);

$t->test(
	'SoundCloud: the paged feed announces its next page',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'soundcloud.xml' );
		$t->same( 'https://feeds.soundcloud.com/users/soundcloud:users:441977205/sounds.rss?before=1139462599', $feed['channel']['next'] );
		$t->same( 'https://feeds.soundcloud.com/users/soundcloud:users:441977205/sounds.rss', $feed['channel']['self'] );
		$t->same( 'tag:soundcloud,2010:tracks/2405137239', $feed['items'][0]['guid'] );
	}
);

$t->test(
	'PowerPress: without itunes:image the last <image> wins over the 32 px site icon',
	static function ( EPM_Test_Runner $t ) {
		$art  = 'https://beyondpodcasting.com/wp-content/uploads/2023/02/BeyondPodasting_3000-livianao-scaled.jpg';
		$xml  = epm_h_file( 'wordpress-powerpress.xml' );
		$feed = ( new FeedParser() )->parse( $xml );
		$t->same( $art, $feed['channel']['image'], 'itunes:image' );

		$stripped = (string) preg_replace( '#<itunes:image\b[^>]*>(\s*</itunes:image>)?#', '', $xml );
		$t->assert( false === strpos( $stripped, '<itunes:image' ), 'fixture edited' );
		$t->same( $art, ( new FeedParser() )->parse( $stripped )['channel']['image'], 'last <image>' );
		$t->same( 'wordpress', Providers::detect_generator( $feed['channel']['generator'] ), 'WordPress generator' );
	}
);

$t->test(
	'Transistor: capitalised explicit "Yes", podcast:guid, locked, self-referencing new-feed-url',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'transistor.xml' );
		$t->same( 'explicit', $feed['channel']['explicit'] );
		$t->same( [ 'clean', 'clean', 'clean' ], array_column( $feed['items'], 'explicit' ), 'items say "No"' );
		$t->same( true, $feed['channel']['locked'] );
		$t->same( '778116ac-6b1e-5ae2-b037-26a7ff2aee64', $feed['channel']['podcast_guid'] );
		$t->same( $feed['channel']['self'], $feed['channel']['new_feed_url'] );
		$t->same( 'https://share.transistor.fm/s/9c22a01c/chapters.json', $feed['items'][0]['chapters_url'] );
		$t->same( 5, count( $feed['items'][0]['transcripts'] ) );
	}
);

$t->test(
	'Megaphone: length="0" enclosures are kept; HTML notes come from content:encoded, the summary is plain text',
	static function ( EPM_Test_Runner $t ) {
		foreach ( epm_h_parse( 'megaphone.xml' )['items'] as $item ) {
			$t->same( 0, $item['audio_length'] );
			$t->assert( 0 === strpos( $item['audio_url'], 'https://traffic.megaphone.fm/' ), 'audio kept' );
			$t->assert( 0 === strpos( $item['html'], '<p' ), 'HTML notes: ' . substr( $item['html'], 0, 40 ) );
			$t->assert( '' !== $item['summary'] && false === strpos( $item['summary'], '<' ), 'plain summary' );
		}
	}
);

$t->test(
	'Captivate: MM:SS and HH:MM:SS durations in one feed',
	static function ( EPM_Test_Runner $t ) {
		$t->same( [ 3973, 3295, 2916 ], array_column( epm_h_parse( 'captivate.xml' )['items'], 'duration' ) );
		$t->same( [ 498, 3398, 3673 ], array_column( epm_h_parse( 'spotify-for-creators.xml' )['items'], 'duration' ), 'zero-padded HH:MM:SS (Spotify for Creators)' );
		$t->same( 157, epm_h_parse( 'libsyn.xml' )['items'][0]['duration'], 'Libsyn "02:37"' );
	}
);

$t->test(
	'Blubrry: an Atom <link> in the default namespace does not replace the RSS link; YouTube-ID GUIDs are kept',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'blubrry.xml' );
		$t->same( 'https://podcast.show/3882499/', $feed['channel']['link'] );
		$t->same( 'https://feeds.blubrry.com/feeds/3882499.xml', $feed['channel']['self'] );
		$t->same( 'ECr3MhL6zjc', $feed['items'][0]['guid'] );
	}
);

$t->test(
	'Acast: XHTML before <channel> and a category after the last item',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'acast.xml' );
		$t->same( [ [ 'Comedy', 'Comedy Interviews' ] ], $feed['channel']['categories'] );
		$t->same( '', $feed['channel']['owner_name'], 'owner name " "' );
		$t->same( 'Off Menu with Ed Gamble and James Acaster', $feed['channel']['title'] );
	}
);

$t->test(
	'RedCircle: entity-escaped content:encoded and an entity-encoded "+" in pubDate',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'redcircle.xml' );
		$t->same( '<p>asdfasdf</p>', $feed['items'][0]['html'] );
		$t->same( strtotime( 'Mon, 25 Aug 2025 16:18:05 +0000' ), $feed['items'][0]['pub_date'] );
		$t->same( '', $feed['channel']['self'], 'no atom:link self' );
	}
);

$t->test(
	'Alitu: a feed without an XML declaration',
	static function ( EPM_Test_Runner $t ) {
		$t->assert( 0 === strpos( epm_h_file( 'alitu.xml' ), '<rss' ), 'fixture starts with <rss' );
		$feed = epm_h_parse( 'alitu.xml' );
		$t->same( 3, count( $feed['items'] ) );
		$t->assert( false !== strpos( $feed['items'][0]['chapters_url'], '_chapters.json?t=' ), 'chapters URL with query' );
	}
);

$t->test(
	'BBC: http:// enclosures stay as published; itunes:new-feed-url differs from an old atom self link',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'bbc-selfhosted.xml' );
		foreach ( $feed['items'] as $item ) {
			$t->assert( 0 === strpos( $item['audio_url'], 'http://open.live.bbc.co.uk/' ), $item['audio_url'] );
		}
		$t->same( 'urn:bbc:podcast:p0c99dsw', $feed['items'][0]['guid'] );
		$t->same( 'https://podcasts.files.bbci.co.uk/p089rfmk.rss', $feed['channel']['new_feed_url'] );
		$t->same( 'http://www.bbc.co.uk/programmes/p089rfmk/episodes/downloads.rss', $feed['channel']['self'] );
	}
);

$t->test(
	'bot-protection pages, an Atom feed and empty bodies are rejected with an error',
	static function ( EPM_Test_Runner $t ) {
		foreach ( glob( EPM_Test_HTTP::dir() . 'negative/*.html' ) as $file ) {
			$result = ( new FeedParser() )->parse( (string) file_get_contents( $file ) );
			$t->assert( is_wp_error( $result ), basename( $file ) );
		}
		$t->same( 2, count( glob( EPM_Test_HTTP::dir() . 'negative/*.html' ) ), 'negative fixtures' );

		$atom = epm_h_parse( 'synthetic/atom.xml' );
		$t->same( 'epm_feed_atom', is_wp_error( $atom ) ? $atom->get_error_code() : 'parsed' );
		foreach ( [ '', "   \n", 'plain text, no markup' ] as $body ) {
			$result = ( new FeedParser() )->parse( $body );
			$t->same( 'epm_feed_empty', is_wp_error( $result ) ? $result->get_error_code() : 'parsed', var_export( $body, true ) );
		}
		$html = ( new FeedParser() )->parse( '<html><body><p>Hello</p></body></html>' );
		$t->same( 'epm_feed_not_rss', is_wp_error( $html ) ? $html->get_error_code() : 'parsed' );
	}
);

$t->test(
	'repairs a BOM, leading whitespace, bare ampersands, HTML named entities and control characters',
	static function ( EPM_Test_Runner $t ) {
		$feed = epm_h_parse( 'synthetic/broken-markup.xml' );
		if ( is_wp_error( $feed ) ) {
			$t->assert( false, $feed->get_error_message() );
			return;
		}
		$t->same( 'Q&A — Friday’s show', $feed['channel']['title'] );
		$t->assert( 0 === strpos( $feed['channel']['description'], 'Café' ), $feed['channel']['description'] );
		$t->assert( false === strpos( $feed['channel']['description'], "\x0B" ), 'control character removed' );
		$t->same( 'Tom & Jerry…', $feed['items'][0]['title'] );
		$t->same( 'https://feeds.example.test/media/broken-1.mp3?a=1&b=2', $feed['items'][0]['audio_url'] );
	}
);

$t->test(
	'SEC-N3: parser preserves a literal less-than sign without whitespace in plain feed text',
	static function ( EPM_Test_Runner $t ) {
		$xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Rock &lt;roll</title><description>For listeners under 18</description><item><title>Use C &lt;3 for speed</title><guid>literal-lt</guid><pubDate>Wed, 03 Jun 2026 08:00:00 +0000</pubDate><enclosure url="https://cdn.example.test/a.mp3" type="audio/mpeg"/></item></channel></rss>';
		$parsed = ( new FeedParser() )->parse( $xml );
		$t->assert( ! is_wp_error( $parsed ), is_wp_error( $parsed ) ? $parsed->get_error_message() : 'parsed' );
		if ( ! is_wp_error( $parsed ) ) {
			$t->same( 'Rock <roll', $parsed['channel']['title'] );
			$t->same( 'Use C <3 for speed', $parsed['items'][0]['title'] ?? '' );
		}
	}
);

$t->test(
	'SEC-N2: parser rejects internal DTD entities before XML expansion',
	static function ( EPM_Test_Runner $t ) {
		$xml = '<!DOCTYPE rss [<!ENTITY a "1234567890"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><rss version="2.0"><channel><title>&b;</title></channel></rss>';
		$parsed = ( new FeedParser() )->parse( $xml );
		$t->assert( is_wp_error( $parsed ), 'entity declaration is rejected' );
		$t->same( 'epm_feed_entities', is_wp_error( $parsed ) ? $parsed->get_error_code() : '', 'specific error code' );
	}
);

$t->test(
	'parser refuses feeds over the configured maximum size',
	static function ( EPM_Test_Runner $t ) {
		$filter = static function () { return 1024; };
		add_filter( 'epm_feed_max_bytes', $filter );
		try {
			$parsed = ( new FeedParser() )->parse( '<rss><channel><title>Large</title></channel></rss>' . str_repeat( ' ', 1024 ) );
			$t->same( 'epm_feed_too_large', is_wp_error( $parsed ) ? $parsed->get_error_code() : '' );
		} finally {
			remove_filter( 'epm_feed_max_bytes', $filter );
		}
	}
);

$t->test(
	'durations: seconds, MM:SS, H:MM:SS, fractions and stray spaces',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'3723'         => 3723,
			'3723.5'       => 3724,
			'62:03'        => 3723,
			'1:02:03'      => 3723,
			'01:02:03.500' => 3724,
			'06: 58'       => 418,
			''             => 0,
			'abc'          => 0,
			'1:2:3:4'      => 0,
		];
		foreach ( $cases as $in => $out ) {
			$t->same( $out, FeedParser::duration_seconds( (string) $in ), var_export( (string) $in, true ) );
		}
	}
);

$t->test(
	'pubDate: a wrong weekday never moves the date; localized and long weekday names and "UT" are understood',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'Mon, 16 Jun 2020 13:13:18 +0200'  => '2020-06-16 11:13:18', // Was a Tuesday: strtotime() alone says Jun 22.
			'Thu, 30 Sep 2026 17:11:52 +0000'  => '2026-09-30 17:11:52', // Was a Wednesday: strtotime() alone says Oct 1.
			'Di, 16 Jun 2020 13:13:18 +0200'   => '2020-06-16 11:13:18',
			'Tues, 16 Jun 2020 13:13:18 +0200' => '2020-06-16 11:13:18',
			'Mié, 16 Jun 2020 13:13:18 GMT'    => '2020-06-16 13:13:18',
			'Tue, 16 Jun 2020 13:13:18 UT'     => '2020-06-16 13:13:18',
			'Tue, 16 Jun 2020 13:13:18 GMT'    => '2020-06-16 13:13:18',
			'June 16, 2020'                    => '2020-06-16 00:00:00',
			'2020-06-16T13:13:18Z'             => '2020-06-16 13:13:18',
		];
		foreach ( $cases as $pub => $expected ) {
			$timestamp = FeedParser::pub_timestamp( $pub );
			$t->same( $expected, false === $timestamp ? 'false' : gmdate( 'Y-m-d H:i:s', $timestamp ), $pub );
		}
		$t->same( false, FeedParser::pub_timestamp( 'not a date' ) );

		// Through the parser: a date with the wrong weekday stays in the past.
		$xml    = str_replace( gmdate( DATE_RSS, gmmktime( 8, 0, 0, 5, 1, 2026 ) ), 'Thu, 30 Sep 2026 06:00:00 +0000', epm_h_sync_feed( [ 1 ] ) );
		$parsed = ( new FeedParser() )->parse( $xml );
		$t->same( gmmktime( 6, 0, 0, 9, 30, 2026 ), is_wp_error( $parsed ) ? $parsed->get_error_message() : $parsed['items'][0]['pub_date'] );
	}
);

$t->test(
	'entity expansion stays bounded: a "billion laughs" feed is refused or harmless, and fast',
	static function ( EPM_Test_Runner $t ) {
		// 50 characters, ten times per level, eight levels: 5 GB if expanded.
		$dtd = '<!ENTITY l0 "' . str_repeat( 'x', 50 ) . '">';
		for ( $level = 1; $level <= 8; $level++ ) {
			$dtd .= '<!ENTITY l' . $level . ' "' . str_repeat( '&l' . ( $level - 1 ) . ';', 10 ) . '">';
		}
		$xml = '<?xml version="1.0"?><!DOCTYPE rss [' . $dtd . ']><rss version="2.0"><channel><title>&l8;</title>'
			. '<item><title>&l8;</title><guid>bomb-1</guid><enclosure url="https://feeds.example.test/media/bomb.mp3" type="audio/mpeg" length="1"/></item></channel></rss>';

		$start  = microtime( true );
		$parsed = ( new FeedParser() )->parse( $xml );
		$took   = microtime( true ) - $start;
		$t->assert( $took < 1.0, sprintf( 'parsed in %.3f s', $took ) );
		if ( ! is_wp_error( $parsed ) ) {
			$t->assert( strlen( (string) $parsed['channel']['title'] ) < MB_IN_BYTES, 'channel title: ' . strlen( (string) $parsed['channel']['title'] ) . ' bytes' );
			foreach ( $parsed['items'] as $item ) {
				$t->assert( strlen( (string) $item['title'] ) < MB_IN_BYTES, 'item title: ' . strlen( (string) $item['title'] ) . ' bytes' );
			}
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Hosts, platforms, addresses and media types' );
/* ------------------------------------------------------------------------- */

$t->test(
	'recognizes the host from the feed address',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'https://anchor.fm/s/f2277cb4/podcast/rss'   => 'spotify',
			'https://rss.buzzsprout.com/231452.rss'      => 'buzzsprout',
			'https://feeds.libsyn.com/45546/rss'         => 'libsyn',
			'https://feed.podbean.com/podcast/feed.xml'  => 'podbean',
			'https://feeds.transistor.fm/build-your-saas' => 'transistor',
			'https://feeds.captivate.fm/insider/'        => 'captivate',
			'https://feeds.acast.com/public/shows/b19ac1f5-6adf-4c8b-aa1a-2af2160f99e4' => 'acast',
			'https://feeds.simplecast.com/6HKOhNgS'      => 'simplecast',
			'https://feeds.megaphone.fm/FSI1483080183'   => 'megaphone',
			'https://podcast-news.podigee.io/feed/mp3'   => 'podigee',
			'https://media.rss.com/podcasting101/feed.xml' => 'rss-com',
			'https://feeds.castos.com/mqv6'              => 'castos',
			'https://feeds.blubrry.com/feeds/3882499.xml' => 'blubrry',
			'https://www.omnycontent.com/d/playlist/f827ca1a/podcast.rss' => 'omny',
			'https://www.spreaker.com/show/1433865/episodes/feed' => 'spreaker',
			'https://rss.art19.com/uniquely-human'       => 'art19',
			'https://audioboom.com/channels/5063197.rss' => 'audioboom',
			'https://feeds.redcircle.com/a1bb6420-9dfd-480d-a035-373c405670aa' => 'redcircle',
			'https://feeds.soundcloud.com/users/soundcloud:users:441977205/sounds.rss' => 'soundcloud',
			'https://api.riverside.fm/hosting/D1YIN18m.rss' => 'riverside',
			'https://beyondpodcasting.com/feed/podcast/' => null,
			'https://podcasts.files.bbci.co.uk/p089rfmk.rss' => null,
			'https://notbuzzsprout.com/feed.rss'         => null,
			'https://example.com/anchor.fm/rss'          => null,
			''                                           => null,
		];
		foreach ( $cases as $url => $provider ) {
			$t->same( $provider, Providers::detect( (string) $url ), (string) $url );
		}
	}
);

$t->test(
	'recognizes the host from the <generator> of the real feeds',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'spotify-for-creators' => 'spotify',
			'buzzsprout'           => 'buzzsprout',
			'libsyn'               => 'libsyn',
			'podbean'              => 'podbean',
			'transistor'           => 'transistor',
			'captivate'            => 'captivate',
			'acast'                => 'acast',
			'simplecast'           => 'simplecast',
			'podigee'              => 'podigee',
			'rss-com'              => 'rss-com',
			'castos'               => 'castos',
			'blubrry'              => 'blubrry',
			'art19'                => 'art19',
			'audioboom'            => 'audioboom',
			'redcircle'            => 'redcircle',
			'riverside'            => 'riverside',
			'wordpress-powerpress' => 'wordpress',
			'alitu'                => null,
			'megaphone'            => null,
		];
		foreach ( $cases as $name => $provider ) {
			$generator = (string) epm_h_parse( $name . '.xml' )['channel']['generator'];
			$t->same( $provider, Providers::detect_generator( $generator ), $name . ' (' . $generator . ')' );
		}
	}
);

$t->test(
	'recognizes the platform of a pasted listing link, most specific host first',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'https://music.youtube.com/playlist?list=PL123' => 'youtube-music',
			'https://www.youtube.com/@show/podcasts'       => 'youtube',
			'https://youtu.be/abc'                         => 'youtube',
			'https://podcasts.apple.com/us/podcast/x/id1'  => 'apple',
			'https://open.spotify.com/show/abc'            => 'spotify',
			'https://music.amazon.com/podcasts/abc'        => 'amazon',
			'https://www.audible.com/pd/abc'               => 'audible',
			'https://pca.st/abc'                           => 'pocketcasts',
			'https://www.iheart.com/podcast/abc/'          => 'iheart',
			'https://example.com/listen'                   => 'custom',
			'not a url'                                    => 'custom',
		];
		foreach ( $cases as $url => $service ) {
			$t->same( $service, Directories::detect_service( $url ), $url );
		}
	}
);

$t->test(
	'normalizes pasted feed addresses and rejects anything that is not an http(s) URL',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'feed://example.com/rss'          => 'https://example.com/rss',
			'feed:https://example.com/rss'    => 'https://example.com/rss',
			'itpc://example.com/rss'          => 'https://example.com/rss',
			'podcast://example.com/rss'       => 'https://example.com/rss',
			'example.com/feed.xml'            => 'https://example.com/feed.xml',
			'  https://example.com/a?b=1  '   => 'https://example.com/a?b=1',
			'http://example.com/feed/'        => 'http://example.com/feed/',
			'https://user:secret@example.com/feed.xml' => 'https://user:secret@example.com/feed.xml',
			'example.com:8080/feed'           => 'https://example.com:8080/feed',
			''                                => '',
			'https://'                        => '',
			'javascript:alert(1)'             => '',
			'not a url'                       => '',
			'ftp://example.com/feed.xml'      => '',
			'mailto:owner@example.com'        => '',
		];
		foreach ( $cases as $in => $out ) {
			$t->same( $out, Hosting::sanitize_feed_url( (string) $in ), var_export( (string) $in, true ) );
		}
		$private_url = 'https://user:secret@example.com/feed.xml?show=1&token=abc';
		$t->assert( Hosting::has_url_secret( $private_url ), 'credentials are recognized and withheld from public metadata' );
		$t->same( '', Hosting::public_safe_url( $private_url ), 'private feeds do not produce a public URL' );
		$t->same( 'self', Hosting::sanitize( [ 'mode' => 'external', 'feed_url' => Feed::url(), 'redirect' => true ] )['mode'], 'cannot configure a redirect back to this site' );
	}
);

$t->test(
	'media types: aliases normalized, unknown types guessed from the URL',
	static function ( EPM_Test_Runner $t ) {
		$mime = [
			[ 'audio/mp3', '', 'audio/mpeg' ],
			[ 'AUDIO/MPEG3', '', 'audio/mpeg' ],
			[ 'audio/x-mp3', '', 'audio/mpeg' ],
			[ 'audio/m4a', '', 'audio/x-m4a' ],
			[ '', 'https://cdn.example.test/a.m4a?x=1', 'audio/x-m4a' ],
			[ 'application/octet-stream', 'https://cdn.example.test/a.mp3', 'audio/mpeg' ],
			[ 'mp3', 'https://cdn.example.test/a.mp4', 'video/mp4' ],
			[ '', '', 'audio/mpeg' ],
			[ 'audio/ogg', 'https://cdn.example.test/a.mp3', 'audio/ogg' ],
		];
		foreach ( $mime as [ $in, $url, $out ] ) {
			$t->same( $out, AudioMetadata::normalize_mime( $in, $url ), $in . ' ' . $url );
		}
		$urls = [
			'https://cdn.example.test/a.MP3'                   => 'audio/mpeg',
			'https://cdn.example.test/a.m4a?token=1'           => 'audio/x-m4a',
			'https://op3.dev/e/cdn.example.test/ep.m4a'        => 'audio/x-m4a',
			'https://cdn.example.test/v.mp4'                   => 'video/mp4',
			'https://cdn.example.test/a.ogg'                   => 'audio/ogg',
			'https://cdn.example.test/stream'                  => 'audio/mpeg',
		];
		foreach ( $urls as $url => $out ) {
			$t->same( $out, AudioMetadata::mime_from_url( $url ), $url );
		}
		$t->assert( AudioMetadata::is_distribution_format( 'audio/x-m4a' ), 'm4a is distributable' );
		$t->assert( ! AudioMetadata::is_distribution_format( 'audio/ogg' ), 'ogg is not distributable' );
		$t->assert( ! AudioMetadata::is_distribution_format( 'audio/wav' ), 'wav is not distributable' );
	}
);

$t->test(
	'hosting settings are sanitized and the host is detected from the feed address',
	static function ( EPM_Test_Runner $t ) {
		$clean = Hosting::sanitize(
			[
				'mode'       => 'external',
				'feed_url'   => 'feed://feeds.buzzsprout.com/123.rss',
				'interval'   => 'weekly',
				'new_status' => 'draft',
				'missing'    => 'draft',
				'sync'       => '1',
			]
		);
		$t->same( 'external', $clean['mode'] );
		$t->same( 'https://feeds.buzzsprout.com/123.rss', $clean['feed_url'] );
		$t->same( 'buzzsprout', $clean['provider'] );
		$t->same( 'hourly', $clean['interval'] );
		$t->same( 'draft', $clean['new_status'] );
		$t->same( 'draft', $clean['missing'] );
		$t->same( true, $clean['sync'] );
		$t->same( false, $clean['redirect'], 'unchecked box' );
		$old = [ 'mode' => 'self', 'feed_url' => '' ];
		$invalid = Hosting::validate_settings_save( [ 'mode' => 'external', 'feed_url' => 'https://feeds.example.test/invalid.xml', 'redirect' => true ], $old );
		$t->same( $old, $invalid, 'unverified external feed leaves old mode and redirect settings untouched' );
		$private = Hosting::sanitize( [ 'mode' => 'external', 'feed_url' => 'https://feeds.example.test/private.xml?token=secret', 'redirect' => 1 ] );
		$t->same( false, $private['redirect'], 'private source cannot be made public by redirect' );
		$previous = Hosting::all();
		update_option( Hosting::OPTION, array_merge( $previous, $private ) );
		$t->same( '', Hosting::feed_redirect_target(), 'private source is never a public redirect target' );
		update_option( Hosting::OPTION, $previous );
		$t->same( 'other', Hosting::sanitize( [ 'provider' => 'made-up-host' ] )['provider'] );
		$t->same( 'other', Hosting::sanitize( [ 'feed_url' => 'https://example.com/feed' ] )['provider'] );
		$t->same( 'self', Hosting::sanitize( [ 'mode' => 'something' ] )['mode'] );
		$t->same( Hosting::defaults(), Hosting::sanitize( 'junk' ) );

		// Another host needs its feed: without one, hosting stays here; a
		// bad address keeps the one saved before. Both are reported once.
		global $wp_settings_errors;
		$errors             = $wp_settings_errors;
		$wp_settings_errors = [];
		try {
			epm_h_hosting( [ 'mode' => 'self' ] );
			$t->same( [ 'self', '' ], array_values( array_intersect_key( Hosting::sanitize( [ 'mode' => 'external', 'feed_url' => 'my show feed' ] ), [ 'mode' => 1, 'feed_url' => 1 ] ) ), 'no feed: stays on this website' );
			Hosting::sanitize( [ 'mode' => 'external' ] );
			$t->same( [ 'epm_hosting_feed' ], array_column( get_settings_errors( 'epm_hosting_group' ), 'code' ), 'reported once' );

			epm_h_hosting(
				[
					'mode'     => 'external',
					'feed_url' => 'https://feeds.buzzsprout.com/123.rss',
				]
			);
			$kept = Hosting::sanitize( array_merge( Hosting::all(), [ 'feed_url' => 'not a feed' ] ) );
			$t->same( [ 'external', 'https://feeds.buzzsprout.com/123.rss' ], [ $kept['mode'], $kept['feed_url'] ], 'a bad address keeps the saved one' );
		} finally {
			$wp_settings_errors = $errors;
			epm_h_restore( Hosting::OPTION );
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Finding the feed' );
/* ------------------------------------------------------------------------- */

$t->test(
	'an Apple Podcasts link resolves to the feed through Apple\'s lookup API',
	static function ( EPM_Test_Runner $t ) {
		EPM_Test_HTTP::$log = [];
		$found              = Hosting::locate( 'https://podcasts.apple.com/us/podcast/synthetic-show/id1000000001?i=1000650000000' );
		$t->assert( ! is_wp_error( $found ), is_wp_error( $found ) ? $found->get_error_message() : '' );
		$t->same( epm_h_url( 'synthetic/locked-show.xml' ), $found['url'] ?? '' );
		$t->same( 'https://itunes.apple.com/lookup?entity=podcast&id=1000000001', EPM_Test_HTTP::$log[0]['url'] ?? '' );
		$t->assert( false !== strpos( (string) ( $found['fetched']['body'] ?? '' ), '<title>Synthetic Show</title>' ), 'feed fetched' );

		$unknown = Hosting::locate( 'https://podcasts.apple.com/us/podcast/nothing/id999' );
		$t->same( 'epm_feed_apple', is_wp_error( $unknown ) ? $unknown->get_error_code() : 'found' );
		$spotify = Hosting::locate( 'https://open.spotify.com/show/2rTT1klKUoQNuaW2Ah19Pa' );
		$t->same( 'epm_feed_spotify', is_wp_error( $spotify ) ? $spotify->get_error_code() : 'found' );
	}
);

$t->test(
	'a web page leads to its podcast feed through <link rel="alternate">',
	static function ( EPM_Test_Runner $t ) {
		$found = Hosting::locate( 'https://show.example.test/' );
		$t->same( epm_h_url( 'synthetic/locked-show.xml' ), is_wp_error( $found ) ? $found->get_error_message() : $found['url'], 'podcast feed preferred over the comments feed' );
		$relative = Hosting::locate( 'show.example.test/relative/' );
		$t->same( 'https://show.example.test/podcast.xml', is_wp_error( $relative ) ? $relative->get_error_message() : $relative['url'], 'relative href' );
		$none = Hosting::locate( 'https://show.example.test/no-feed/' );
		$t->same( 'epm_feed_html', is_wp_error( $none ) ? $none->get_error_code() : 'found' );
	}
);

$t->test(
	'a WordPress page leads to its podcast feed, not the blog feed listed first',
	static function ( EPM_Test_Runner $t ) {
		$feed  = static function () {
			return EPM_Test_HTTP::response( 200, epm_h_sync_feed( [ 1 ] ), [ 'content-type' => 'application/rss+xml' ] );
		};
		$page  = static function ( array $links ): callable {
			$head = '<!DOCTYPE html><html><head><title>Site</title>';
			foreach ( $links as [ $href, $title ] ) {
				$head .= '<link rel="alternate" type="application/rss+xml" title="' . esc_attr( $title ) . '" href="' . esc_url( $href ) . '" />';
			}
			return static function () use ( $head ) {
				return EPM_Test_HTTP::response( 200, $head . '</head><body></body></html>', [ 'content-type' => 'text/html' ] );
			};
		};
		$site  = 'https://wp.example.test/';
		$pages = [
			// This plugin: WordPress prints the blog and comment feeds first.
			'plugin/'     => [
				[ [ $site . 'feed/', 'Coffee Talk &raquo; Feed' ], [ $site . 'comments/feed/', 'Coffee Talk &raquo; Comments Feed' ], [ $site . 'podcast/feed/', 'Coffee Talk' ] ],
				$site . 'podcast/feed/',
			],
			// PowerPress on a site whose name says "Podcast".
			'powerpress/' => [
				[ [ $site . 'feed/', 'The Daily Podcast &raquo; Feed' ], [ $site . 'comments/feed/', 'The Daily Podcast &raquo; Comments Feed' ], [ $site . 'feed/podcast/', 'The Daily Podcast &raquo; Podcast Feed' ] ],
				$site . 'feed/podcast/',
			],
			// A host's show page.
			'host/'       => [
				[ [ $site . 'feed/', 'Blog' ], [ 'https://feeds.example.test/show.rss', 'Show' ] ],
				'https://feeds.example.test/show.rss',
			],
			// Only the blog feed: still found.
			'blog-only/'  => [
				[ [ $site . 'feed/', 'Just a blog &raquo; Feed' ], [ $site . 'comments/feed/', 'Just a blog &raquo; Comments Feed' ] ],
				$site . 'feed/',
			],
		];
		foreach ( [ 'feed/', 'comments/feed/', 'podcast/feed/', 'feed/podcast/' ] as $path ) {
			EPM_Test_HTTP::$routes[ $site . $path ] = $feed;
		}
		EPM_Test_HTTP::$routes['https://feeds.example.test/show.rss'] = $feed;
		try {
			foreach ( $pages as $path => [ $links, $expected ] ) {
				EPM_Test_HTTP::$routes[ $site . 'page/' . $path ] = $page( $links );
				$found = Hosting::locate( $site . 'page/' . $path );
				$t->same( $expected, is_wp_error( $found ) ? $found->get_error_message() : $found['url'], $path );
			}
		} finally {
			foreach ( array_keys( EPM_Test_HTTP::$routes ) as $route ) {
				if ( 0 === strpos( $route, $site ) || 'https://feeds.example.test/show.rss' === $route ) {
					unset( EPM_Test_HTTP::$routes[ $route ] );
				}
			}
		}
	}
);

$t->test(
	'bot-protection pages and HTTP errors are reported, not parsed',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ 'negative/cloudflare-challenge-http403.html', 'negative/siteground-captcha-http202.html' ] as $path ) {
			$result = Hosting::locate( epm_h_url( $path ) );
			$t->same( 'epm_feed_blocked', is_wp_error( $result ) ? $result->get_error_code() : 'found', $path );
		}
		// The same challenge page with status 200.
		EPM_Test_HTTP::$routes[ epm_h_url( 'challenge-200' ) ] = static function () {
			return EPM_Test_HTTP::response( 200, epm_h_file( 'negative/cloudflare-challenge-http403.html' ), [ 'content-type' => 'text/html' ] );
		};
		$result = Hosting::locate( epm_h_url( 'challenge-200' ) );
		$t->same( 'epm_feed_blocked', is_wp_error( $result ) ? $result->get_error_code() : 'found', 'challenge served with 200' );
		$missing = Hosting::locate( epm_h_url( 'does-not-exist.xml' ) );
		$t->same( 'epm_feed_http_address', is_wp_error( $missing ) ? $missing->get_error_code() : 'found', '404' );
		$empty = Hosting::locate( 'https://' );
		$t->same( 'epm_feed_url', is_wp_error( $empty ) ? $empty->get_error_code() : 'found', 'no address' );
		unset( EPM_Test_HTTP::$routes[ epm_h_url( 'challenge-200' ) ] );
	}
);

$t->test(
	'a permanent redirect (301/308) updates the feed address; a temporary one does not',
	static function ( EPM_Test_Runner $t ) {
		$body = epm_h_file( 'synthetic/paged-2.xml' );
		foreach ( [ 'moved-301' => [ 301 ], 'moved-308' => [ 301, 308 ], 'moved-302' => [ 302 ], 'moved-mixed' => [ 301, 302 ] ] as $name => $hops ) {
			EPM_Test_HTTP::$routes[ epm_h_url( $name . '.xml' ) ] = static function () use ( $body, $hops ) {
				return EPM_Test_HTTP::response( 200, $body, [ 'content-type' => 'application/rss+xml' ], epm_h_url( 'synthetic/paged-2.xml' ), $hops );
			};
		}
		$expect = [
			'moved-301'   => epm_h_url( 'synthetic/paged-2.xml' ),
			'moved-308'   => epm_h_url( 'synthetic/paged-2.xml' ),
			'moved-302'   => epm_h_url( 'moved-302.xml' ),
			'moved-mixed' => epm_h_url( 'moved-mixed.xml' ),
		];
		foreach ( $expect as $name => $url ) {
			$found = Hosting::locate( epm_h_url( $name . '.xml' ) );
			$t->same( $url, is_wp_error( $found ) ? $found->get_error_message() : $found['url'], $name );
			unset( EPM_Test_HTTP::$routes[ epm_h_url( $name . '.xml' ) ] );
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Import' );
/* ------------------------------------------------------------------------- */

$epm_h_show = epm_h_url( 'synthetic/locked-show.xml' );

$t->test(
	'the preview reports episodes, duplicates, hidden and tracked episodes, the lock and the host',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_show ) {
		$preview = ImportJob::preview( $epm_h_show );
		if ( is_wp_error( $preview ) ) {
			$t->assert( false, $preview->get_error_message() );
			return;
		}
		$t->same( $epm_h_show, $preview['feed_url'] );
		$t->same( 'Synthetic Show', $preview['channel']['title'] );
		$t->same( 5, $preview['episodes'], 'unique episodes' );
		$t->same( 5, $preview['with_audio'] );
		$t->same( 0, $preview['existing'] );
		$t->same( [ 'Duplicate of episode 1' ], $preview['duplicates'] );
		$t->same( 1, $preview['blocked'] );
		$t->same( 1, $preview['tracked'], 'op3.dev prefix' );
		$t->same( true, $preview['locked'] );
		$t->same( 'C0FFEE00-1234-5ABC-8DEF-0123456789AB', $preview['podcast_guid'] );
		$t->same( null, $preview['provider'] );
		$t->same( '', $preview['moved_to'] );
		$t->assert( '' !== $preview['newest'] && '' !== $preview['oldest'], 'date range' );
		$t->assert( ! isset( $preview['channel']['description_html'] ), 'raw HTML not sent to the browser' );
		$t->same( 'ready', ImportJob::get()['status'] ?? '' );
		$t->same( 5, ImportJob::get()['total'] ?? 0 );

		$real = ImportJob::preview( epm_h_url( 'buzzsprout.xml' ) );
		$t->same( 'buzzsprout', is_wp_error( $real ) ? $real->get_error_message() : $real['provider'], 'host from <generator>' );
		$t->same( 'Buzzsprout', $real['provider_name'] ?? '' );
		$t->same( 3, $real['tracked'] ?? 0, 'op3/podtrac chain' );
		$t->same( true, $real['locked'] ?? null );
		ImportJob::cancel();
	}
);

$t->test(
	'the preview follows rel="next" pages once and reports a show that moved elsewhere',
	static function ( EPM_Test_Runner $t ) {
		EPM_Test_HTTP::$log = [];
		$paged              = ImportJob::preview( epm_h_url( 'synthetic/paged-1.xml' ) );
		$t->same( 4, is_wp_error( $paged ) ? $paged->get_error_message() : $paged['episodes'], 'both pages' );
		$t->same( 1, count( epm_h_requests( epm_h_url( 'synthetic/paged-1.xml' ) ) ), 'page 1 fetched once although page 2 links back' );
		$t->same( 1, count( epm_h_requests( epm_h_url( 'synthetic/paged-2.xml' ) ) ), 'page 2 fetched once' );

		$moved = str_replace( '<generator>', '<itunes:new-feed-url>https://feeds.example.test/new-home.xml</itunes:new-feed-url><generator>', epm_h_file( 'synthetic/locked-show.xml' ) );
		$self  = str_replace( '<generator>', '<itunes:new-feed-url>https://feeds.example.test/old-home.xml/</itunes:new-feed-url><generator>', epm_h_file( 'synthetic/locked-show.xml' ) );
		EPM_Test_HTTP::$routes[ epm_h_url( 'old-home.xml' ) ] = static function () use ( &$moved ) {
			return EPM_Test_HTTP::response( 200, $moved, [ 'content-type' => 'application/rss+xml' ] );
		};
		$preview = ImportJob::preview( epm_h_url( 'old-home.xml' ) );
		$t->same( 'https://feeds.example.test/new-home.xml', is_wp_error( $preview ) ? $preview->get_error_message() : $preview['moved_to'] );
		$moved   = $self;
		$preview = ImportJob::preview( epm_h_url( 'old-home.xml' ) );
		$t->same( '', is_wp_error( $preview ) ? $preview->get_error_message() : $preview['moved_to'], 'a feed naming itself is not a move' );
		unset( EPM_Test_HTTP::$routes[ epm_h_url( 'old-home.xml' ) ] );
		ImportJob::cancel();
	}
);

$t->test(
	'importing maps every field, keeps GUIDs and dates, and skips duplicates',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_show ) {
		EPM_Test_HTTP::$log = [];
		$run                = epm_h_import(
			$epm_h_show,
			[
				'purpose' => 'mirror',
				'status'  => 'publish',
			]
		);
		if ( is_wp_error( $run ) ) {
			$t->assert( false, $run->get_error_message() );
			return;
		}
		$t->same( 'done', $run['state']['status'] );
		$t->same( 5, $run['state']['counts']['created'] );
		$t->same( 0, $run['state']['counts']['failed'] );

		$guids = [ 'tag:show.example.test,2026:episodes/3', 'https://show.example.test/?p=102&preview=false', 'Synthetic-0001', 'synthetic-blocked', 'synthetic-undated' ];
		foreach ( $guids as $guid ) {
			$ids = epm_h_ids( $guid );
			$t->same( 1, count( $ids ), 'one episode for ' . $guid );
			$t->same( $guid, get_post_meta( $ids[0] ?? 0, '_epm_guid', true ), 'GUID kept byte-for-byte' );
		}
		[ $ep3, $ep2, $ep1, $blocked, $undated ] = array_map( 'epm_h_id', $guids );

		$t->same( 'Episode 1: HTML transcript', get_the_title( $ep1 ), 'the first of two items with one GUID wins' );
		$t->same( '2026-06-03 08:00:00', get_post_field( 'post_date_gmt', $ep3 ) );
		$t->same( '2026-06-02 08:00:00', get_post_field( 'post_date_gmt', $ep2 ) );
		$t->assert( (int) get_post_field( 'post_author', $ep1 ) > 0, 'sync-created episodes have a real author' );
		$t->same( [ 'publish', 'publish', 'publish', 'draft', 'draft' ], array_map( 'get_post_status', [ $ep3, $ep2, $ep1, $blocked, $undated ] ), 'itunes:block and undated items become drafts' );
		$t->same( 'import', get_post_meta( $ep2, '_epm_source', true ) );
		$t->same( $epm_h_show, get_post_meta( $ep2, '_epm_source_feed', true ) );

		// Audio stays at the host.
		$t->same( 'https://op3.dev/e/feeds.example.test/media/ep3.mp3', get_post_meta( $ep3, '_epm_audio_url', true ) );
		$t->same( 'audio/mpeg', get_post_meta( $ep3, '_epm_audio_type', true ), 'audio/mp3 normalized' );
		$t->same( 0, (int) get_post_meta( $ep3, '_epm_audio_length', true ) );
		$t->same( 12345678, (int) get_post_meta( $ep2, '_epm_audio_length', true ) );
		$t->same( 'audio/x-m4a', get_post_meta( $ep1, '_epm_audio_type', true ) );
		$t->same( 0, (int) get_post_meta( $ep2, '_epm_audio_id', true ), 'no Media Library copy' );

		$t->same( '25:30', get_post_meta( $ep3, '_epm_duration', true ) );
		$t->same( 1530, (int) get_post_meta( $ep3, '_epm_duration_seconds', true ) );
		$t->same( '1:02:03', get_post_meta( $ep2, '_epm_duration', true ) );
		$t->same( [ '3', '1', 'full', 'inherit' ], [ get_post_meta( $ep3, '_epm_episode_number', true ), get_post_meta( $ep3, '_epm_season_number', true ), get_post_meta( $ep3, '_epm_episode_type', true ), get_post_meta( $ep3, '_epm_explicit', true ) ] );
		$t->same( 'trailer', get_post_meta( $ep1, '_epm_episode_type', true ) );
		$t->same( 'explicit', get_post_meta( $ep2, '_epm_explicit', true ) );
		$t->same( 'https://feeds.example.test/media/ep2-art.png', get_post_meta( $ep2, '_epm_artwork_url', true ) );
		$t->same( 'https://show.example.test/episodes/2', get_post_meta( $ep2, '_epm_source_link', true ) );
		$t->same( 'Gia Guest', get_post_meta( $ep3, '_epm_guest_name', true ), 'podcast:person role="guest"' );

		// Notes.
		$notes3 = (string) get_post_field( 'post_content', $ep3 );
		$t->assert( false !== strpos( $notes3, '<p>First line of the notes.</p>' ), 'plain-text notes become paragraphs: ' . $notes3 );
		$t->assert( false !== strpos( $notes3, '<a href="https://show.example.test/notes/3"' ), 'and links: ' . $notes3 );
		$notes2 = (string) get_post_field( 'post_content', $ep2 );
		$t->assert( false !== strpos( $notes2, '<a href="https://show.example.test/">a link</a>' ) && false === strpos( $notes2, '<script' ), 'HTML notes kept, scripts removed: ' . $notes2 );
		$t->same( 'First line of the notes. More at https://show.example.test/notes/3', get_post_meta( $ep3, '_epm_short_description', true ) );
		$t->same( 'Short plain summary of episode 2.', get_post_meta( $ep2, '_epm_short_description', true ) );

		// Chapters: inline Podlove chapters need no request; JSON chapters are fetched.
		$chapters = static function ( int $id ): array {
			return array_map(
				static function ( $c ) {
					return [ $c['time'], $c['title'], $c['url'] ];
				},
				(array) get_post_meta( $id, '_epm_chapters', true )
			);
		};
		$t->same( [ [ '0:00', 'Opening', '' ], [ '1:30', 'Topic & more', 'https://show.example.test/topic' ], [ '1:02:03', 'Wrap-up', '' ] ], $chapters( $ep2 ), 'psc:chapters' );
		$t->same( [], epm_h_requests( epm_h_url( 'synthetic/extras/ep2-chapters.json' ) ), 'inline chapters win without a request' );
		$t->same( [ [ '0:00', 'Intro', '' ], [ '1:05', 'Main part', 'https://show.example.test/main' ], [ '1:01:40', 'Late', '' ] ], $chapters( $ep3 ), 'JSON chapters without toc:false entries' );

		// Transcripts: HTML preferred; WebVTT and SRT become speaker paragraphs.
		$html = (string) get_post_meta( $ep1, '_epm_transcript', true );
		$t->assert( false !== strpos( $html, '<p><strong>Sam Synth:</strong> Hello and welcome.</p>' ) && false === strpos( $html, 'lose against' ), 'HTML transcript preferred over SRT: ' . $html );
		$t->assert( false === strpos( $html, '<script' ) && false === strpos( $html, '<title' ), 'body only, no scripts: ' . $html );
		$t->same( "<p><strong>Sam Synth:</strong> Welcome to the show. Today we test captions.</p>\n<p><strong>Gia Guest:</strong> Glad to be here &amp; ready.</p>", get_post_meta( $ep2, '_epm_transcript', true ), 'WebVTT' );
		$t->same( "<p><strong>Sam:</strong> Plain SRT line one. And line two.</p>\n<p><strong>Gia:</strong> A reply.</p>", get_post_meta( $ep3, '_epm_transcript', true ), 'SRT' );

		// The timed file stays at the host (mirror, no media copies):
		// WebVTT first, then SRT; the HTML page is not a timed file.
		$kept = static function ( int $id ): array {
			return [ get_post_meta( $id, '_epm_transcript_url', true ), get_post_meta( $id, '_epm_transcript_type', true ), (int) get_post_meta( $id, '_epm_transcript_file_id', true ) ];
		};
		$t->same( [ epm_h_url( 'synthetic/extras/ep2.vtt' ), 'text/vtt', 0 ], $kept( $ep2 ), 'WebVTT kept as a link' );
		$t->same( [ epm_h_url( 'synthetic/extras/ep3.srt' ), 'application/x-subrip', 0 ], $kept( $ep3 ), 'SRT kept as a link' );
		$t->same( [ epm_h_url( 'synthetic/extras/ep1.srt' ), 'application/x-subrip', 0 ], $kept( $ep1 ), 'SRT kept next to the HTML transcript' );
		$t->same( [ '', '', 0 ], $kept( $blocked ), 'no transcript, nothing kept' );

		// The site's feed carries the imported episodes with the host's media.
		$xp   = epm_test_xpath( epm_test_feed() );
		$item = epm_h_item( $xp, 'https://show.example.test/?p=102&preview=false' );
		$t->assert( null !== $item, 'episode 2 in the feed' );
		if ( $item ) {
			$enclosure = $xp->query( 'enclosure', $item )->item( 0 );
			$t->same( 'https://feeds.example.test/media/ep2.mp3', $enclosure->getAttribute( 'url' ) );
			$t->same( '12345678', $enclosure->getAttribute( 'length' ) );
			$t->same( 'audio/mpeg', $enclosure->getAttribute( 'type' ) );
			$t->same( 'https://feeds.example.test/media/ep2-art.png', $xp->query( 'itunes:image/@href', $item )->item( 0 )->nodeValue ?? '', 'remote episode artwork' );
			$t->same( '3723', $xp->query( 'itunes:duration', $item )->item( 0 )->textContent ?? '' );
			$t->same(
				[
					[ epm_h_url( 'synthetic/extras/ep2.vtt' ), 'text/vtt', 'captions' ],
					[ Feed::transcript_url( $ep2 ), 'text/html', '' ],
				],
				epm_h_transcripts( $xp, $item ),
				'captions file at the host first, then the transcript page'
			);
		}
		$m4a = epm_h_item( $xp, 'Synthetic-0001' );
		$t->same(
			[
				[ epm_h_url( 'synthetic/extras/ep1.srt' ), 'application/x-subrip', 'captions' ],
				[ Feed::transcript_url( $ep1 ), 'text/html', '' ],
			],
			$m4a ? epm_h_transcripts( $xp, $m4a ) : [],
			'SRT captions'
		);
		$t->same( 'audio/x-m4a', $m4a ? $xp->query( 'enclosure/@type', $m4a )->item( 0 )->nodeValue : 'missing', 'm4a episode' );
		$t->same( null, epm_h_item( $xp, 'synthetic-blocked' ), 'draft not in the feed' );
	}
);

$t->test(
	'importing the same feed again updates episodes instead of duplicating them',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_show ) {
		$before = epm_h_from_feed( $epm_h_show );
		$run    = epm_h_import(
			$epm_h_show,
			[
				'purpose' => 'mirror',
				'status'  => 'publish',
			]
		);
		if ( is_wp_error( $run ) ) {
			$t->assert( false, $run->get_error_message() );
			return;
		}
		$t->same( 5, $run['preview']['existing'], 'preview knows them' );
		$t->same( 0, $run['state']['counts']['created'] );
		$t->same( 5, $run['state']['counts']['unchanged'] + $run['state']['counts']['updated'] );
		$after = epm_h_from_feed( $epm_h_show );
		sort( $before );
		sort( $after );
		$t->same( $before, $after );
	}
);

$t->test(
	'GUIDs with %-escapes (WordPress permalink GUIDs) are stored unchanged and never duplicated',
	static function ( EPM_Test_Runner $t ) {
		$url  = epm_h_url( 'percent-guid.xml' );
		$guid = 'https://example.test/podcast/f%C3%BCr-alle/';
		$xml  = epm_h_sync_feed( [ 1 ] );
		$xml  = str_replace( '<guid isPermaLink="false">sync-1</guid>', '<guid isPermaLink="true">' . $guid . '</guid>', $xml );
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		foreach ( [ 1, 2 ] as $round ) {
			$run = epm_h_import(
				$url,
				[
					'purpose' => 'mirror',
					'status'  => 'publish',
				]
			);
			$t->assert( ! is_wp_error( $run ), 'import ' . $round );
		}
		$t->same( 1, count( epm_h_from_feed( $url ) ), 'episodes after two imports' );
		$t->same( [ $guid ], array_values( array_unique( array_map( [ Episodes::class, 'get_guid' ], epm_h_from_feed( $url ) ) ) ), 'stored GUID' );
		unset( EPM_Test_HTTP::$routes[ $url ] );
	}
);

$t->test(
	'a Podcasting 2.0 JSON transcript becomes readable text and stays linked, even when media are copied',
	static function ( EPM_Test_Runner $t ) {
		$url  = epm_h_url( 'json-transcript.xml' );
		$json = epm_h_url( 'json-transcript/1.json' );
		$xml  = str_replace(
			[ '<rss version="2.0"', '</item>' ],
			[ '<rss version="2.0" xmlns:podcast="https://podcastindex.org/namespace/1.0"', '<podcast:transcript url="' . $json . '" type="application/json"/></item>' ],
			epm_h_sync_feed( [ 1 ] )
		);
		EPM_Test_HTTP::$routes[ $url ]  = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		EPM_Test_HTTP::$routes[ $json ] = static function () {
			return EPM_Test_HTTP::response(
				200,
				(string) wp_json_encode(
					[
						'version'  => '1.0.0',
						'segments' => [
							[
								'speaker'   => 'Sam Synth',
								'startTime' => 0,
								'endTime'   => 2,
								'body'      => 'Hello',
							],
							[
								'speaker'   => 'Sam Synth',
								'startTime' => 2,
								'endTime'   => 4,
								'body'      => 'and welcome.',
							],
							[
								'speaker'   => 'Gia Guest',
								'startTime' => 4,
								'endTime'   => 6,
								'body'      => 'Thanks <b>&amp;</b> hi.',
							],
							[
								'startTime' => 6,
								'endTime'   => 7,
								'body'      => '   ',
							],
						],
					]
				),
				[ 'content-type' => 'application/json' ]
			);
		};
		try {
			$run = epm_h_import(
				$url,
				[
					'purpose'        => 'mirror',
					'status'         => 'publish',
					'download_media' => true,
				]
			);
			$t->same( 'done', is_wp_error( $run ) ? $run->get_error_message() : $run['state']['status'] );
			$id = epm_h_id( 'sync-1' );
			$t->same( "<p><strong>Sam Synth:</strong> Hello and welcome.</p>\n<p><strong>Gia Guest:</strong> Thanks &amp; hi.</p>", get_post_meta( $id, '_epm_transcript', true ) );
			$t->same( [ $json, 'application/json', 0 ], [ get_post_meta( $id, '_epm_transcript_url', true ), get_post_meta( $id, '_epm_transcript_type', true ), (int) get_post_meta( $id, '_epm_transcript_file_id', true ) ], 'JSON is linked, never copied' );

			$xp   = epm_test_xpath( epm_test_feed() );
			$item = epm_h_item( $xp, 'sync-1' );
			$t->same( [ [ $json, 'application/json', '' ], [ Feed::transcript_url( $id ), 'text/html', '' ] ], $item ? epm_h_transcripts( $xp, $item ) : [], 'JSON is no captions file' );

			$t->same( '', Importer::transcript_html( '{"segments": "nope"}', 'application/json' ), 'malformed JSON' );
			$t->same( '', Importer::transcript_html( 'not json', 'application/json' ), 'not JSON' );
		} finally {
			unset( EPM_Test_HTTP::$routes[ $url ], EPM_Test_HTTP::$routes[ $json ] );
			foreach ( epm_h_from_feed( $url ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

$t->test(
	'moving a locked show needs the owner\'s consent, then copies media, adopts podcast:guid and prepares the feed',
	static function ( EPM_Test_Runner $t ) {
		// A copy of the synthetic show with its own GUIDs (nothing imported yet).
		$url = epm_h_url( 'move-show.xml' );
		$xml = (string) preg_replace( '#<guid isPermaLink="false">([^<]+)</guid>#', '<guid isPermaLink="false">move-$1</guid>', epm_h_file( 'synthetic/locked-show.xml' ) );
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		delete_option( Feed::GUID_OPTION );
		$settings               = epm()->settings->all();
		$settings['feed_limit'] = 2;
		$settings['moved_in']   = false;
		$settings['locked']     = false;
		update_option( PodcastSettings::OPTION, $settings );

		try {
			$preview = ImportJob::preview( $url );
			$refused = ImportJob::start(
				(string) ( $preview['token'] ?? '' ),
				[
					'purpose'        => 'move',
					'download_media' => true,
				]
			);
			$t->same( 'epm_import_locked', is_wp_error( $refused ) ? $refused->get_error_code() : 'started', 'no consent' );
			$t->same( 'ready', ImportJob::get()['status'] ?? '', 'the job waits' );

			$state = ImportJob::start(
				(string) $preview['token'],
				[
					'purpose'        => 'move',
					'download_media' => true,
					'confirm_owner'  => true,
					'apply_channel'  => true,
				]
			);
			for ( $i = 0; $i < 200 && ! is_wp_error( $state ) && 'running' === $state['status']; $i++ ) {
				$state = ImportJob::step( 8.0 );
			}
			$t->same( 'done', is_wp_error( $state ) ? $state->get_error_message() : $state['status'] );
			$t->same( 5, $state['counts']['created'] ?? 0 );

			// Media copied into the Media Library.
			foreach ( [ 'move-tag:show.example.test,2026:episodes/3', 'move-https://show.example.test/?p=102&preview=false', 'move-Synthetic-0001' ] as $guid ) {
				$id    = epm_h_id( $guid );
				$audio = (int) get_post_meta( $id, '_epm_audio_id', true );
				$t->assert( $audio > 0 && 'attachment' === get_post_type( $audio ), $guid . ' audio copied' );
				$t->assert( 0 === strpos( (string) get_post_mime_type( $audio ), 'audio/' ), $guid . ' audio type ' . get_post_mime_type( $audio ) );
				$t->assert( (int) get_post_meta( $id, '_epm_audio_size', true ) > 0, $guid . ' size' );
			}
			$ep2 = epm_h_id( 'move-https://show.example.test/?p=102&preview=false' );
			$t->same( 'https://feeds.example.test/media/ep2.mp3', get_post_meta( (int) get_post_meta( $ep2, '_epm_audio_id', true ), '_epm_source_url', true ), 'source remembered' );
			$t->assert( (int) get_post_meta( $ep2, '_epm_artwork_id', true ) > 0, 'episode image copied' );

			// Timed transcripts are copied with the media (the import may
			// continue in the background, without a signed-in user).
			foreach (
				[
					'move-https://show.example.test/?p=102&preview=false' => [ 'ep2.vtt', 'text/vtt' ],
					'move-tag:show.example.test,2026:episodes/3'          => [ 'ep3.srt', 'application/x-subrip' ],
					'move-Synthetic-0001'                                 => [ 'ep1.srt', 'application/x-subrip' ],
				] as $guid => [ $file, $type ]
			) {
				$id   = epm_h_id( $guid );
				$copy = (int) get_post_meta( $id, '_epm_transcript_file_id', true );
				$t->assert( $copy > 0 && 'attachment' === get_post_type( $copy ), $guid . ': ' . $file . ' copied (link: ' . get_post_meta( $id, '_epm_transcript_url', true ) . ')' );
				$t->same( '', get_post_meta( $id, '_epm_transcript_url', true ), $guid . ': no link next to the copy' );
				$t->same( epm_h_url( 'synthetic/extras/' . $file ), $copy ? get_post_meta( $copy, '_epm_source_url', true ) : '', $guid . ': source remembered' );
				$t->same( $type, $copy ? Transcripts::mime( (string) get_attached_file( $copy ) ) : '', $guid . ': file type' );
			}

			// finish_move(): every episode stays in the feed, the feed names its new home and is locked.
			$after = epm()->settings->all();
			$t->same( 0, (int) $after['feed_limit'], 'feed limit lifted' );
			$t->same( true, $after['moved_in'] );
			$t->same( true, $after['locked'], 'locked (owner email present)' );
			$t->same( 'c0ffee00-1234-5abc-8def-0123456789ab', get_option( Feed::GUID_OPTION ), 'podcast:guid adopted' );

			$xp = epm_test_xpath( epm_test_feed() );
			$t->same( Feed::url(), $xp->query( '/rss/channel/itunes:new-feed-url' )->item( 0 )->textContent ?? '' );
			$t->same( 'yes', $xp->query( '/rss/channel/podcast:locked' )->item( 0 )->textContent ?? '' );
			$t->same( 'c0ffee00-1234-5abc-8def-0123456789ab', $xp->query( '/rss/channel/podcast:guid' )->item( 0 )->textContent ?? '' );
			$item = epm_h_item( $xp, 'move-https://show.example.test/?p=102&preview=false' );
			$url2 = $item ? $xp->query( 'enclosure/@url', $item )->item( 0 )->nodeValue : '';
			$t->assert( false !== strpos( (string) $url2, '/wp-content/uploads/' ), 'enclosure served from this site: ' . $url2 );
			$captions = $item ? epm_h_transcripts( $xp, $item ) : [];
			$t->assert( false !== strpos( (string) ( $captions[0][0] ?? '' ), '/wp-content/uploads/' ) && '.vtt' === substr( (string) ( $captions[0][0] ?? '' ), -4 ), 'captions served from this site: ' . wp_json_encode( $captions ) );
			$t->same( [ 'text/vtt', 'captions' ], [ $captions[0][1] ?? '', $captions[0][2] ?? '' ] );
		} finally {
			unset( EPM_Test_HTTP::$routes[ $url ] );
			epm_h_restore( PodcastSettings::OPTION );
			epm_h_restore( Feed::GUID_OPTION );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'moving a show that was mirrored before copies its media too',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_show ) {
		$mirrored = epm_h_id( 'Synthetic-0001' );
		$t->same( 0, (int) get_post_meta( $mirrored, '_epm_audio_id', true ), 'mirrored without media' );
		try {
			$run = epm_h_import(
				$epm_h_show,
				[
					'purpose'        => 'move',
					'download_media' => true,
					'confirm_owner'  => true,
				]
			);
			$t->same( 'done', is_wp_error( $run ) ? $run->get_error_message() : $run['state']['status'] );
			foreach ( [ 'tag:show.example.test,2026:episodes/3', 'Synthetic-0001' ] as $guid ) {
				$t->assert( (int) get_post_meta( epm_h_id( $guid ), '_epm_audio_id', true ) > 0, $guid . ' has a local copy of its audio' );
			}
		} finally {
			epm_h_restore( PodcastSettings::OPTION );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'show details: empty settings are filled, overwriting is explicit, the website address is never taken',
	static function ( EPM_Test_Runner $t ) {
		$channel = epm_h_parse( 'synthetic/locked-show.xml' )['channel'];
		$seeded  = epm()->settings->all();
		try {
			$changed = Importer::apply_channel( $channel, false, false );
			$now     = epm()->settings->all();
			$t->same( 'Test & Talk Podcast', $now['title'], 'title kept' );
			$t->same( 'Technology', $now['category'], 'category kept' );
			$t->same( 'jane@example.com', $now['owner_email'], 'owner kept' );
			$t->same( $seeded['website_url'], $now['website_url'] );
			$t->same( 'Made-up episodes for tests', $now['short_description'], 'empty field filled' );
			$t->same( 'Sam Synth', $now['host'] );
			$t->same( 'https://show.example.test/support', $now['funding_url'] );
			$t->same( [ 'funding_label', 'funding_url', 'host', 'short_description' ], ( static function ( $keys ) {
				sort( $keys );
				return $keys;
			} )( $changed ), 'changed keys' );

			$changed = Importer::apply_channel( $channel, true, true );
			$now     = epm()->settings->all();
			$t->same( 'Synthetic Show', $now['title'] );
			$t->same( '<p>A <strong>made-up</strong> show used by the test suites.</p>', $now['description'] );
			$t->same( [ 'Business', 'Marketing' ], [ $now['category'], $now['subcategory'] ] );
			$t->same( 'en_GB', $now['language'] );
			$t->same( 'owner@show.example.test', $now['owner_email'] );
			$t->same( $seeded['website_url'], $now['website_url'], 'website address never taken from the feed' );
			$art = (int) $now['artwork_id'];
			$t->assert( $art > 0 && $art !== (int) $seeded['artwork_id'], 'artwork copied' );
			$t->same( 'https://feeds.example.test/media/show-art.png', get_post_meta( $art, '_epm_source_url', true ) );
			Importer::apply_channel( $channel, true, true );
			$t->same( $art, (int) epm()->settings->get( 'artwork_id' ), 'artwork reused, not copied twice' );

			delete_option( PodcastSettings::OPTION );
			Importer::apply_channel( $channel, false, false );
			$t->same( 'Synthetic Show', epm()->settings->get( 'title' ), 'a fresh site takes everything' );
		} finally {
			epm_h_restore( PodcastSettings::OPTION );
		}
	}
);

$t->test(
	'podcast:guid is adopted on a fresh site, derived from the old feed address without one, kept otherwise',
	static function ( EPM_Test_Runner $t ) {
		$channel = epm_h_parse( 'synthetic/locked-show.xml' )['channel'];
		try {
			delete_option( Feed::GUID_OPTION );
			Importer::apply_channel( $channel, false, false, epm_h_url( 'synthetic/locked-show.xml' ) );
			$t->same( 'c0ffee00-1234-5abc-8def-0123456789ab', get_option( Feed::GUID_OPTION ), 'adopted, lowercase' );

			update_option( Feed::GUID_OPTION, '11111111-2222-5333-8444-555555555555', false );
			Importer::apply_channel( $channel, false, false );
			$t->same( '11111111-2222-5333-8444-555555555555', get_option( Feed::GUID_OPTION ), 'kept without overwrite' );
			Importer::apply_channel( $channel, true, false );
			$t->same( 'c0ffee00-1234-5abc-8def-0123456789ab', get_option( Feed::GUID_OPTION ), 'replaced with overwrite' );

			delete_option( Feed::GUID_OPTION );
			$without = array_merge( $channel, [ 'podcast_guid' => '' ] );
			Importer::apply_channel( $without, false, false, 'https://feeds.example.test/old/feed/' );
			$t->same( Feed::uuid_v5( Feed::GUID_NAMESPACE, 'feeds.example.test/old/feed' ), get_option( Feed::GUID_OPTION ), 'derived like Podcast Index' );

			delete_option( Feed::GUID_OPTION );
			Importer::apply_channel( array_merge( $channel, [ 'podcast_guid' => 'not-a-uuid' ] ), false, false );
			$t->same( false, get_option( Feed::GUID_OPTION ), 'invalid value ignored' );
		} finally {
			epm_h_restore( PodcastSettings::OPTION );
			epm_h_restore( Feed::GUID_OPTION );
		}
	}
);

$t->test(
	'a move keeps the show\'s podcast:guid without "fill in empty settings": it replaces a guid this site only derived, never one set on purpose',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_show ) {
		try {
			// The first request of this site's feed stored a derived guid.
			update_option( Feed::GUID_OPTION, Feed::derived_podcast_guid(), false );
			$run = epm_h_import(
				$epm_h_show,
				[
					'purpose'       => 'move',
					'confirm_owner' => true,
				]
			);
			$t->same( 'done', is_wp_error( $run ) ? $run->get_error_message() : $run['state']['status'] );
			$t->same( 'c0ffee00-1234-5abc-8def-0123456789ab', get_option( Feed::GUID_OPTION ), 'adopted over the derived guid' );

			update_option( Feed::GUID_OPTION, '11111111-2222-5333-8444-555555555555', false );
			epm_h_import(
				$epm_h_show,
				[
					'purpose'       => 'move',
					'confirm_owner' => true,
				]
			);
			$t->same( '11111111-2222-5333-8444-555555555555', get_option( Feed::GUID_OPTION ), 'a guid set here is kept' );

			delete_option( Feed::GUID_OPTION );
			epm_h_import(
				$epm_h_show,
				[
					'purpose' => 'mirror',
				]
			);
			$t->same( false, get_option( Feed::GUID_OPTION ), 'mirroring never adopts it' );
		} finally {
			epm_h_restore( PodcastSettings::OPTION );
			epm_h_restore( Feed::GUID_OPTION );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'moving a show here while mirroring its host switches hosting to this website: no sync, no redirect back (no loop)',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_show ) {
		try {
			epm_h_hosting(
				[
					'mode'     => 'external',
					'feed_url' => $epm_h_show,
					'sync'     => true,
					'redirect' => true,
				]
			);
			$t->same( $epm_h_show, Hosting::feed_redirect_target(), 'mirroring: the feed redirects to the host' );
			$run = epm_h_import(
				$epm_h_show,
				[
					'purpose'       => 'move',
					'confirm_owner' => true,
				]
			);
			$t->same( 'done', is_wp_error( $run ) ? $run->get_error_message() : $run['state']['status'] );
			$t->same( 'self', Hosting::get( 'mode' ) );
			$t->same( '', Hosting::feed_redirect_target(), 'no redirect to the old host' );
			$t->same( false, Hosting::sync_enabled() );
			$t->same( false, wp_next_scheduled( Hosting::CRON_HOOK ), 'sync unscheduled' );
			$t->same( true, epm()->settings->get( 'moved_in' ) );

			// A plain import (mirror) changes nothing about hosting.
			epm_h_hosting( [ 'mode' => 'external' ] );
			epm_h_import( $epm_h_show, [ 'purpose' => 'mirror' ] );
			$t->same( 'external', Hosting::get( 'mode' ), 'mirror import' );
		} finally {
			epm_h_restore( Hosting::OPTION );
			epm_h_restore( PodcastSettings::OPTION );
			epm_h_restore( Feed::GUID_OPTION );
			wp_clear_scheduled_hook( Hosting::CRON_HOOK );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'audio that could not be copied is counted and listed, on new and existing episodes',
	static function ( EPM_Test_Runner $t ) {
		$url = epm_h_url( 'missing-audio.xml' );
		$xml = str_replace( 'https://feeds.example.test/media/sync-1.mp3', 'https://feeds.example.test/gone/sync-1.mp3', epm_h_sync_feed( [ 1, 2 ] ) );
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		try {
			foreach ( [ 'new episodes' => 2, 'existing episodes' => 0 ] as $label => $created ) {
				$run = epm_h_import(
					$url,
					[
						'purpose'        => 'mirror',
						'download_media' => true,
					]
				);
				if ( is_wp_error( $run ) ) {
					$t->assert( false, $run->get_error_message() );
					return;
				}
				$state = $run['state'];
				$t->same( [ 'done', $created, 1 ], [ $state['status'], $state['counts']['created'], $state['counts']['media_failed'] ], $label );
				$t->same( [ 'Sync episode 1' ], array_column( $state['media_failed'], 'title' ), $label . ': listed' );
				$t->same( 0, (int) get_post_meta( epm_h_id( 'sync-1' ), '_epm_audio_id', true ), $label . ': audio stays at the host' );
				$t->assert( (int) get_post_meta( epm_h_id( 'sync-2' ), '_epm_audio_id', true ) > 0, $label . ': the other audio was copied' );
			}
			$t->same( 0, ImportJob::client_state( [] )['counts']['media_failed'], 'no job: zero' );
		} finally {
			unset( EPM_Test_HTTP::$routes[ $url ] );
			foreach ( epm_h_from_feed( $url ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

$t->test(
	'an episode whose audio another request is copying is not downloaded twice',
	static function ( EPM_Test_Runner $t ) {
		$url = epm_h_url( 'copying.xml' );
		$xml = epm_h_sync_feed( [ 1 ] );
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		try {
			epm_h_import( $url, [ 'purpose' => 'mirror' ] );
			$id = epm_h_id( 'sync-1' );
			update_post_meta( $id, '_epm_copying', time() - 60 );
			EPM_Test_HTTP::$log = [];
			epm_h_import(
				$url,
				[
					'purpose'        => 'mirror',
					'download_media' => true,
				]
			);
			$t->same( [], epm_h_requests( 'https://feeds.example.test/media/sync-1.mp3' ), 'no second download while one runs' );
			$t->same( 0, (int) get_post_meta( $id, '_epm_audio_id', true ) );

			update_post_meta( $id, '_epm_copying', time() - 18 * MINUTE_IN_SECONDS );
			epm_h_import(
				$url,
				[
					'purpose'        => 'mirror',
					'download_media' => true,
				]
			);
			$t->assert( (int) get_post_meta( $id, '_epm_audio_id', true ) > 0, 'a stale marker (crashed request) does not block the copy' );
			$t->same( '', get_post_meta( $id, '_epm_copying', true ), 'marker removed after the copy' );
		} finally {
			unset( EPM_Test_HTTP::$routes[ $url ] );
			foreach ( epm_h_from_feed( $url ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

$t->test(
	'stopping an import while a step runs: the step never undoes the cancel, and a cancelled move is not finished',
	static function ( EPM_Test_Runner $t ) {
		$url = epm_h_url( 'cancel.xml' );
		$xml = str_replace( '<guid isPermaLink="false">sync-', '<guid isPermaLink="false">cancel-', epm_h_sync_feed( [ 1, 2, 3 ] ) );
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		$settings             = epm()->settings->all();
		$settings['moved_in'] = false;
		update_option( PodcastSettings::OPTION, $settings );
		// "Stop the import" pressed while the step imports its first episode.
		$cancel = static function ( $post_id, $post ) {
			if ( EpisodePostType::CPT === $post->post_type ) {
				remove_action( 'wp_insert_post', $GLOBALS['epm_h_cancel'], 10 );
				ImportJob::cancel();
			}
		};
		$GLOBALS['epm_h_cancel'] = $cancel;
		try {
			$preview = ImportJob::preview( $url );
			ImportJob::start(
				(string) $preview['token'],
				[
					'purpose' => 'move',
				]
			);
			add_action( 'wp_insert_post', $cancel, 10, 2 );
			$state = ImportJob::step( 8.0 );
			$t->same( 'cancelled', $state['status'], 'the step reports the cancel' );
			$t->same( 'cancelled', ImportJob::get()['status'] ?? '', 'and keeps it' );
			$t->same( false, wp_next_scheduled( ImportJob::CRON_HOOK ), 'no background continuation' );
			$t->same( false, epm()->settings->get( 'moved_in' ), 'the move is not finished' );
			$t->same( 'cancelled', ImportJob::step( 8.0 )['status'], 'a later step does nothing' );
		} finally {
			remove_action( 'wp_insert_post', $cancel, 10 );
			unset( EPM_Test_HTTP::$routes[ $url ], $GLOBALS['epm_h_cancel'] );
			foreach ( epm_h_from_feed( $url ) as $id ) {
				wp_delete_post( $id, true );
			}
			epm_h_restore( PodcastSettings::OPTION );
			epm_h_restore( Feed::GUID_OPTION );
			wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Sync with the host' );
/* ------------------------------------------------------------------------- */

$epm_h_sync = epm_h_url( 'sync.xml' );

$GLOBALS['epm_h_sync'] = [
	'status'  => 200,
	'numbers' => range( 1, 12 ),
	'changes' => [],
	'extra'   => '',
];
EPM_Test_HTTP::$routes[ $epm_h_sync ] = static function ( $args ) {
	$s = $GLOBALS['epm_h_sync'];
	if ( 200 !== $s['status'] ) {
		return EPM_Test_HTTP::response( $s['status'], 'Server error' );
	}
	$body = epm_h_sync_feed( $s['numbers'], $s['changes'], $s['extra'] );
	$etag = '"' . md5( $body ) . '"';
	if ( ( $args['headers']['If-None-Match'] ?? '' ) === $etag ) {
		return EPM_Test_HTTP::response( 304, '', [ 'etag' => $etag ] );
	}

	return EPM_Test_HTTP::response(
		200,
		$body,
		[
			'content-type'  => 'application/rss+xml',
			'etag'          => $etag,
			'last-modified' => 'Wed, 30 Sep 2026 06:00:00 GMT',
		]
	);
};

$t->test(
	'the first sync mirrors every episode and remembers the validators',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		delete_option( Hosting::STATE_OPTION );
		epm_h_hosting(
			[
				'mode'     => 'external',
				'feed_url' => $epm_h_sync,
				'sync'     => true,
				'missing'  => 'draft',
				'redirect' => true,
			]
		);
		$result = Hosting::sync();
		$t->same( 'ok', $result['status'], $result['message'] );
		$t->same( 12, $result['created'] );
		$state = Hosting::state();
		$t->same( '"' . md5( epm_h_sync_feed( range( 1, 12 ) ) ) . '"', $state['etag'] );
		$t->same( 'Wed, 30 Sep 2026 06:00:00 GMT', $state['last_modified'] );
		$t->same( 12, $state['items'] );
		$t->same( 0, $state['failures'] );
		$t->same( 'Sync Show', $state['feed_title'] );
		$t->same( 12, count( epm_h_from_feed( $epm_h_sync, 'publish' ) ) );
	}
);

$t->test(
	'an unchanged feed answers the conditional request with 304; "Sync now" asks for the whole feed',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		EPM_Test_HTTP::$log = [];
		$result             = Hosting::sync();
		$t->same( [ 'ok', 0, 0 ], [ $result['status'], $result['created'], $result['updated'] ] );
		$sent = epm_h_requests( $epm_h_sync )[0]['headers'] ?? [];
		$t->same( Hosting::state()['etag'], $sent['if-none-match'] ?? '', 'If-None-Match' );
		$t->same( 'Wed, 30 Sep 2026 06:00:00 GMT', $sent['if-modified-since'] ?? '', 'If-Modified-Since' );

		EPM_Test_HTTP::$log = [];
		$forced             = Hosting::sync( true );
		$t->same( [ 'ok', 0, 0 ], [ $forced['status'], $forced['created'], $forced['updated'] ] );
		$t->assert( ! isset( epm_h_requests( $epm_h_sync )[0]['headers']['if-none-match'] ), 'no validators when forced' );
	}
);

$t->test(
	'signed enclosure URL rotation does not update an episode each sync',
	static function ( EPM_Test_Runner $t ) {
		$url = epm_h_url( 'signed.xml' );
		$signature = 'one';
		$make = static function () use ( &$signature ) {
			return str_replace( 'https://feeds.example.test/media/sync-1.mp3', 'https://cdn.example.test/sync-1.mp3?X-Amz-Signature=' . $signature . '&X-Amz-Expires=60', epm_h_sync_feed( [ 1 ] ) );
		};
		$route = static function () use ( $make ) { return EPM_Test_HTTP::response( 200, $make(), [ 'content-type' => 'application/rss+xml' ] ); };
		EPM_Test_HTTP::$routes[ $url ] = $route;
		epm_h_hosting( [ 'feed_url' => $url, 'mode' => 'external' ] );
		Hosting::sync( true );
		$id = epm_h_id( 'sync-1' );
		$modified = get_post_modified_time( 'U', true, $id );
		$signature = 'two';
		$result = Hosting::sync( true );
		$t->same( 0, $result['updated'], $result['message'] );
		$t->same( $modified, get_post_modified_time( 'U', true, $id ), 'post timestamp remains unchanged' );
		unset( EPM_Test_HTTP::$routes[ $url ] );
		// Restore the suite's sync feed so later tests do not inherit a URL
		// whose temporary route has just been removed.
		epm_h_hosting( [ 'feed_url' => epm_h_url( 'sync.xml' ), 'mode' => 'external' ] );
		Hosting::sync( true );
	}
);

$t->test(
	'a title edited here survives a sync while fields untouched here follow the host',
	static function ( EPM_Test_Runner $t ) {
		$ep5 = epm_h_id( 'sync-5' );
		wp_update_post(
			[
				'ID'         => $ep5,
				'post_title' => 'Edited on this site',
			]
		);
		$GLOBALS['epm_h_sync']['changes'][5] = [
			'title'    => 'Renamed at the host',
			'notes'    => 'New notes for 5.',
			'duration' => 900,
		];
		$GLOBALS['epm_h_sync']['changes'][6] = [ 'notes' => 'New notes for 6.' ];
		$result                              = Hosting::sync();
		$t->same( 2, $result['updated'], $result['message'] );
		$t->same( 'Edited on this site', get_post_field( 'post_title', $ep5 ) );
		$t->same( '15:00', get_post_meta( $ep5, '_epm_duration', true ), 'duration followed the host' );
		$t->assert( false !== strpos( (string) get_post_field( 'post_content', $ep5 ), 'New notes for 5.' ), 'notes followed the host' );
		$t->assert( false !== strpos( (string) get_post_field( 'post_content', epm_h_id( 'sync-6' ) ), 'New notes for 6.' ), 'episode 6 notes' );
		$t->same( 'Sync episode 6', get_post_field( 'post_title', epm_h_id( 'sync-6' ) ) );
	}
);

$t->test(
	'saving a synced episode in the classic editor is no local edit: later changes at the host still arrive',
	static function ( EPM_Test_Runner $t ) {
		$ep8 = epm_h_id( 'sync-8' );
		$GLOBALS['epm_h_sync']['changes'][8] = [ 'notes' => "Paragraph one.\n\nParagraph two." ];
		Hosting::sync();
		$stored = (string) get_post_field( 'post_content', $ep8 );
		$t->assert( false !== strpos( $stored, '<p>Paragraph one.</p>' ), 'notes synced as paragraphs: ' . $stored );

		// What the classic editor sends back on "Update": no <p> tags, CRLF.
		wp_update_post(
			[
				'ID'           => $ep8,
				'post_content' => "Paragraph one.\r\n\r\nParagraph two.",
			]
		);

		$GLOBALS['epm_h_sync']['changes'][8] = [ 'notes' => "Paragraph one, corrected.\n\nParagraph two." ];
		$result                              = Hosting::sync();
		$t->assert( false !== strpos( (string) get_post_field( 'post_content', $ep8 ), 'Paragraph one, corrected.' ), 'the host\'s correction arrived (' . $result['message'] . ')' );

		// A real edit is still kept.
		wp_update_post(
			[
				'ID'           => $ep8,
				'post_content' => 'Rewritten on this site.',
			]
		);
		$GLOBALS['epm_h_sync']['changes'][8] = [ 'notes' => "Paragraph one, again.\n\nParagraph two." ];
		Hosting::sync();
		$t->same( 'Rewritten on this site.', get_post_field( 'post_content', $ep8 ), 'local edit kept' );
		unset( $GLOBALS['epm_h_sync']['changes'][8] );
	}
);

$t->test(
	'an episode deleted here is not brought back by the sync',
	static function ( EPM_Test_Runner $t ) {
		wp_trash_post( epm_h_id( 'sync-7' ) );
		$GLOBALS['epm_h_sync']['changes'][7] = [ 'title' => 'Changed at the host after the deletion' ];
		$result                              = Hosting::sync();
		$t->same( 0, $result['created'], $result['message'] );
		$t->same( 1, count( epm_h_ids( 'sync-7' ) ) );
		$t->same( 'trash', get_post_status( epm_h_id( 'sync-7' ) ) );
	}
);

$t->test(
	'a truncated feed changes nothing and is reported',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		$published                        = epm_h_from_feed( $epm_h_sync, 'publish' );
		$GLOBALS['epm_h_sync']['numbers'] = [ 9, 10, 11, 12 ];
		$result                           = Hosting::sync();
		$t->same( 'error', $result['status'] );
		$t->same( [ 0, 0, 0 ], [ $result['created'], $result['updated'], $result['drafted'] ] );
		$t->assert( false !== strpos( $result['message'], '4' ) && false !== strpos( $result['message'], '12' ), $result['message'] );
		$state = Hosting::state();
		$t->same( [ 'error', 1, 12 ], [ $state['status'], $state['failures'], $state['items'] ] );
		$t->same( $published, epm_h_from_feed( $epm_h_sync, 'publish' ), 'nothing unpublished' );
		$t->same( '', get_post_meta( epm_h_id( 'sync-1' ), '_epm_missing_since', true ), 'nothing marked missing' );
	}
);

$t->test(
	'an empty feed changes nothing, even when the sync is forced',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		$published                        = epm_h_from_feed( $epm_h_sync, 'publish' );
		$GLOBALS['epm_h_sync']['numbers'] = [];
		$t->same( 'error', Hosting::sync()['status'] );
		$t->same( 'error', Hosting::sync( true )['status'], 'forced' );
		$t->same( 3, Hosting::state()['failures'] );
		$t->same( $published, epm_h_from_feed( $epm_h_sync, 'publish' ) );
		Hosting::update_state( [ 'failures' => 0 ] );
	}
);

$t->test(
	'episodes the host removed are drafted after a day, only within the feed\'s time window',
	static function ( EPM_Test_Runner $t ) {
		// 1 (the oldest) and 3 disappear; the oldest item left is 2.
		$GLOBALS['epm_h_sync']['numbers'] = [ 2, 4, 5, 6, 7, 8, 9, 10, 11, 12 ];
		$result                           = Hosting::sync();
		$t->same( [ 'ok', 0 ], [ $result['status'], $result['drafted'] ], $result['message'] );
		$ep3 = epm_h_id( 'sync-3' );
		$ep1 = epm_h_id( 'sync-1' );
		$t->same( 'publish', get_post_status( $ep3 ), 'not on the first miss' );
		$t->assert( abs( time() - (int) get_post_meta( $ep3, '_epm_missing_since', true ) ) < 60, 'first miss recorded' );
		$t->same( '', get_post_meta( $ep1, '_epm_missing_since', true ), 'older than the feed window: not considered' );

		update_post_meta( $ep3, '_epm_missing_since', time() - 2 * DAY_IN_SECONDS );
		$GLOBALS['epm_h_sync']['numbers'][] = 13;
		$result                             = Hosting::sync();
		$t->same( [ 1, 1 ], [ $result['created'], $result['drafted'] ], $result['message'] );
		$t->same( 'draft', get_post_status( $ep3 ) );
		$t->same( '', get_post_meta( $ep3, '_epm_missing_since', true ) );
		$t->same( 'publish', get_post_status( $ep1 ) );
		$t->same( 'publish', get_post_status( epm_h_id( 'sync-13' ) ) );
	}
);

$t->test(
	'the sync follows a feed that announces a new address or moved permanently, never to this site\'s own feed',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		EPM_Test_HTTP::$routes[ epm_h_url( 'sync-moved.xml' ) ] = static function () {
			$s = $GLOBALS['epm_h_sync'];
			return EPM_Test_HTTP::response( 200, epm_h_sync_feed( $s['numbers'], $s['changes'] ), [ 'content-type' => 'application/rss+xml' ] );
		};
		$GLOBALS['epm_h_sync']['extra'] = '<itunes:new-feed-url>https://feeds.example.test/sync-moved.xml</itunes:new-feed-url>';
		$result                         = Hosting::sync();
		$t->same( 'ok', $result['status'], $result['message'] );
		$t->same( 'https://feeds.example.test/sync-moved.xml', Hosting::get( 'feed_url' ) );
		$t->assert( false !== strpos( $result['message'], 'verified as this show' ), 'verified move is reported' );
		unset( EPM_Test_HTTP::$routes[ epm_h_url( 'sync-moved.xml' ) ] );

		// The host sends apps here (this site's feed, with or without the
		// trailing slash): the show moved here, so hosting switches to this
		// website instead of redirecting back to the host (a loop).
		foreach ( [ Feed::url(), untrailingslashit( Feed::url() ) ] as $own ) {
			epm_h_hosting(
				[
					'mode'     => 'external',
					'feed_url' => $epm_h_sync,
				]
			);
			$GLOBALS['epm_h_sync']['extra'] = '<itunes:new-feed-url>' . esc_xml( $own ) . '</itunes:new-feed-url>';
			$moved                          = Hosting::sync();
			$t->same( $epm_h_sync, Hosting::get( 'feed_url' ), 'this site\'s own feed is never followed: ' . $own );
			$t->same( [ 'self', '' ], [ Hosting::get( 'mode' ), Hosting::feed_redirect_target() ], 'hosting switched to this website: ' . $own );
			$t->assert( false !== strpos( $moved['message'], 'This website' ), $moved['message'] );
		}
		epm_h_hosting( [ 'mode' => 'external' ] );

		// An https feed never moves to http.
		$GLOBALS['epm_h_sync']['extra'] = '<itunes:new-feed-url>http://feeds.example.test/sync-plain.xml</itunes:new-feed-url>';
		Hosting::sync();
		$t->same( $epm_h_sync, Hosting::get( 'feed_url' ), 'no move from https to http' );
		$GLOBALS['epm_h_sync']['extra'] = '';

		// Redirected by the host: permanent moves are followed, temporary ones not.
		EPM_Test_HTTP::$routes[ epm_h_url( 'sync-final.xml' ) ] = static function () {
			$s = $GLOBALS['epm_h_sync'];
			return EPM_Test_HTTP::response( 200, epm_h_sync_feed( $s['numbers'], $s['changes'] ), [ 'content-type' => 'application/rss+xml' ] );
		};
		foreach ( [ 'sync-302.xml' => [ [ 302 ], 'sync-302.xml' ], 'sync-301.xml' => [ [ 301 ], 'sync-final.xml' ] ] as $name => [ $hops, $expected ] ) {
			EPM_Test_HTTP::$routes[ epm_h_url( $name ) ] = static function () use ( $hops ) {
				$s = $GLOBALS['epm_h_sync'];
				return EPM_Test_HTTP::response( 200, epm_h_sync_feed( $s['numbers'], $s['changes'] ), [ 'content-type' => 'application/rss+xml' ], epm_h_url( 'sync-final.xml' ), $hops );
			};
			epm_h_hosting( [ 'feed_url' => epm_h_url( $name ) ] );
			$result = Hosting::sync();
			unset( EPM_Test_HTTP::$routes[ epm_h_url( $name ) ] );
			$t->same( epm_h_url( $expected ), Hosting::get( 'feed_url' ), $hops[0] . ' (' . $result['message'] . ')' );
		}
		epm_h_hosting( [ 'feed_url' => $epm_h_sync ] );
		unset( EPM_Test_HTTP::$routes[ epm_h_url( 'sync-final.xml' ) ] );
	}
);

$t->test(
	'sync rejects an announced feed address that cannot be fetched before adopting it',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		$bad = epm_h_url( 'not-a-feed.xml' );
		$bad_feed = '<html>not a feed</html>';
		$GLOBALS['epm_h_sync']['extra'] = '<itunes:new-feed-url>' . esc_xml( $bad ) . '</itunes:new-feed-url>';
		EPM_Test_HTTP::$routes[ $bad ] = static fn () => EPM_Test_HTTP::response( 200, $bad_feed, [ 'content-type' => 'application/rss+xml' ] );
		$before = Hosting::get( 'feed_url' );
		$start   = count( EPM_Test_HTTP::$log );
		$result = Hosting::sync();
		$t->same( 'ok', $result['status'], $result['message'] );
		$t->same( $before, Hosting::get( 'feed_url' ), 'an address containing no episodes is not adopted' );
		$t->assert( false !== strpos( $result['message'], 'current address was kept' ), 'rejected move is explained' );
		$t->assert( in_array( $bad, array_column( array_slice( EPM_Test_HTTP::$log, $start ), 'url' ), true ), 'candidate address is fetched before adoption' );
		unset( EPM_Test_HTTP::$routes[ $bad ] );
		$GLOBALS['epm_h_sync']['extra'] = '';
	}
);

$t->test(
	'after the host feed moved, episodes the host removed are still found (re-tagged with the new address)',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		$renamed                          = epm_h_url( 'sync-renamed.xml' );
		EPM_Test_HTTP::$routes[ $renamed ] = EPM_Test_HTTP::$routes[ $epm_h_sync ];
		$numbers                          = $GLOBALS['epm_h_sync']['numbers'];
		try {
			epm_h_hosting( [ 'feed_url' => $renamed ] );
			$result = Hosting::sync();
			$t->same( 'ok', $result['status'], $result['message'] );
			$t->same( $renamed, get_post_meta( epm_h_id( 'sync-10' ), '_epm_source_feed', true ), 'listed episodes carry the new address' );

			// Episode 10 disappears at the new address.
			$GLOBALS['epm_h_sync']['numbers'] = array_values( array_diff( $numbers, [ 10 ] ) );
			Hosting::sync();
			$t->assert( (int) get_post_meta( epm_h_id( 'sync-10' ), '_epm_missing_since', true ) > 0, 'the removal is noticed' );
		} finally {
			$GLOBALS['epm_h_sync']['numbers'] = $numbers;
			unset( EPM_Test_HTTP::$routes[ $renamed ] );
			delete_post_meta( epm_h_id( 'sync-10' ), '_epm_missing_since' );
			epm_h_hosting( [ 'feed_url' => $epm_h_sync ] );
			Hosting::sync();
		}
	}
);

$t->test(
	'failures are counted and the scheduled sync backs off: 1 h after the third, 2 h after the fourth',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		Hosting::update_state( [ 'failures' => 0 ] );
		$GLOBALS['epm_h_sync']['status'] = 500;
		$result                          = Hosting::sync();
		$t->same( 'error', $result['status'] );
		$t->assert( false !== strpos( $result['message'], '500' ), $result['message'] );
		$t->same( 1, Hosting::state()['failures'] );

		$runs = static function (): int {
			EPM_Test_HTTP::$log = [];
			Hosting::run_scheduled_sync();
			return count( EPM_Test_HTTP::$log );
		};
		Hosting::update_state(
			[
				'failures' => 3,
				'last_run' => time() - 30 * MINUTE_IN_SECONDS,
			]
		);
		$t->same( 0, $runs(), '3 failures, 30 min ago: waits' );
		Hosting::update_state( [ 'last_run' => time() - 61 * MINUTE_IN_SECONDS ] );
		$t->same( 1, $runs(), '3 failures, 61 min ago: runs' );
		$t->same( 4, Hosting::state()['failures'] );
		Hosting::update_state( [ 'last_run' => time() - 90 * MINUTE_IN_SECONDS ] );
		$t->same( 0, $runs(), '4 failures, 90 min ago: waits' );
		Hosting::update_state( [ 'last_run' => time() - 121 * MINUTE_IN_SECONDS ] );
		$t->same( 1, $runs(), '4 failures, 121 min ago: runs' );

		$GLOBALS['epm_h_sync']['status'] = 200;
		Hosting::update_state( [ 'last_run' => time() - DAY_IN_SECONDS ] );
		$t->same( 1, $runs(), 'at most a day' );
		$t->same( [ 'ok', 0 ], [ Hosting::state()['status'], Hosting::state()['failures'] ], 'success resets the counter' );

		epm_h_hosting( [ 'sync' => false ] );
		$t->same( 0, $runs(), 'automatic sync turned off' );
		epm_h_hosting( [ 'sync' => true ] );
	}
);

$t->test(
	'one import or sync at a time; the schedule follows the settings',
	static function ( EPM_Test_Runner $t ) use ( $epm_h_sync ) {
		$t->assert( ImportJob::acquire_lock(), 'lock taken' );
		$t->same( 'busy', Hosting::sync()['status'] );
		ImportJob::release_lock();
		$t->same( false, get_option( 'epm_import_lock' ), 'released' );
		update_option( 'epm_import_lock', (string) ( time() - 10 * MINUTE_IN_SECONDS ), false );
		$t->assert( ImportJob::acquire_lock(), 'a stale lock is taken over' );
		ImportJob::release_lock();

		// A lock belongs to the request that took it: one taken over by
		// another request is not released by the first one.
		$t->assert( ImportJob::acquire_lock(), 'lock taken again' );
		$other = time() . ':another-request';
		update_option( 'epm_import_lock', $other, false );
		$t->same( false, ImportJob::refresh_lock(), 'not ours any more' );
		ImportJob::release_lock();
		$t->same( $other, get_option( 'epm_import_lock' ), 'the other request\'s lock stays' );
		delete_option( 'epm_import_lock' );

		// Refreshed while held; a running copy of media keeps it for longer.
		$t->assert( ImportJob::acquire_lock(), 'lock taken once more' );
		$taken = (int) strtok( (string) get_option( 'epm_import_lock' ), ':' );
		sleep( 1 );
		$t->assert( ImportJob::refresh_lock(), 'refreshed' );
		$t->assert( (int) strtok( (string) get_option( 'epm_import_lock' ), ':' ) > $taken, 'with the current time' );
		$t->assert( ImportJob::refresh_lock(), 'refreshed again within the same second' );
		// A lock row someone else rewrote (even with the same owner part)
		// is not this request's lock any more.
		$suffix = substr( (string) get_option( 'epm_import_lock' ), strpos( (string) get_option( 'epm_import_lock' ), ':' ) );
		$rewritten = ( time() - 100 ) . $suffix;
		update_option( 'epm_import_lock', $rewritten, false );
		$t->same( false, ImportJob::refresh_lock(), 'a rewritten lock is not renewed' );
		ImportJob::release_lock();
		$t->same( $rewritten, get_option( 'epm_import_lock' ), 'nor released' );
		delete_option( 'epm_import_lock' );

		$job = get_option( ImportJob::OPTION );
		update_option(
			ImportJob::OPTION,
			[
				'status'  => 'running',
				'options' => [ 'download_media' => true ],
			],
			false
		);
		update_option( 'epm_import_lock', ( time() - 10 * MINUTE_IN_SECONDS ) . ':copying', false );
		$t->same( false, ImportJob::acquire_lock(), 'copying media: 10 minutes is not stale' );
		update_option( 'epm_import_lock', ( time() - 21 * MINUTE_IN_SECONDS ) . ':copying', false );
		$t->assert( ImportJob::acquire_lock(), 'copying media: 21 minutes is stale' );
		ImportJob::release_lock();
		update_option( ImportJob::OPTION, $job, false );

		epm_h_hosting(
			[
				'mode'     => 'external',
				'feed_url' => $epm_h_sync,
				'sync'     => true,
				'interval' => 'twicedaily',
			]
		);
		$event = wp_get_scheduled_event( Hosting::CRON_HOOK );
		$t->same( 'twicedaily', $event ? $event->schedule : 'none' );
		epm_h_hosting( [ 'sync' => false ] );
		$t->same( false, wp_next_scheduled( Hosting::CRON_HOOK ), 'sync off' );
		epm_h_hosting(
			[
				'sync' => true,
				'mode' => 'self',
			]
		);
		$t->same( false, wp_next_scheduled( Hosting::CRON_HOOK ), 'self-hosted' );
	}
);

unset( EPM_Test_HTTP::$routes[ $epm_h_sync ] );
epm_h_restore( Hosting::OPTION );
epm_h_restore( Hosting::STATE_OPTION );

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Feed output and redirects' );
/* ------------------------------------------------------------------------- */

$t->test(
	'an episode with only an external audio URL is in the feed with its enclosure, type and remote image',
	static function ( EPM_Test_Runner $t ) {
		$make = static function ( string $title, string $date, array $meta ): int {
			$id = (int) wp_insert_post(
				[
					'post_type'   => 'podcast_episode',
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => $date,
				]
			);
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, '_epm_' . $key, $value );
			}
			Episodes::sync_duration_seconds( $id );
			Episodes::clear_data_cache( $id );
			return $id;
		};
		$m4a = $make(
			'External M4A',
			'2026-09-20 10:00:00',
			[
				'audio_url'    => 'https://cdn.example.test/audio/ext-1.m4a?token=abc&x=1',
				'audio_length' => 7654321,
				'artwork_url'  => 'https://cdn.example.test/img/ext-1.jpg',
				'duration'     => '12:34',
			]
		);
		$mp3 = $make(
			'External MP3 typed audio/mp3',
			'2026-09-19 10:00:00',
			[
				'audio_url'  => 'https://cdn.example.test/audio/ext-2',
				'audio_type' => 'audio/mp3',
			]
		);
		$ogg = $make( 'External OGG', '2026-09-18 10:00:00', [ 'audio_url' => 'https://cdn.example.test/audio/ext-3.ogg' ] );

		$data = epm()->episodes->get_data( $m4a );
		$t->same( [ 'external', true ], [ $data['audio_source'], $data['has_audio'] ] );
		$t->same( $m4a, epm()->episodes->get_latest( true )->ID ?? 0, 'counts as the latest episode with audio' );

		$xp   = epm_test_xpath( epm_test_feed() );
		$item = epm_h_item( $xp, Episodes::get_guid( $m4a ) );
		$t->assert( null !== $item, 'external episode in the feed' );
		if ( $item ) {
			$t->same( 'https://cdn.example.test/audio/ext-1.m4a?token=abc&x=1', $xp->query( 'enclosure/@url', $item )->item( 0 )->nodeValue );
			$t->same( '7654321', $xp->query( 'enclosure/@length', $item )->item( 0 )->nodeValue );
			$t->same( 'audio/x-m4a', $xp->query( 'enclosure/@type', $item )->item( 0 )->nodeValue, 'type from the file extension' );
			$t->same( 'https://cdn.example.test/img/ext-1.jpg', $xp->query( 'itunes:image/@href', $item )->item( 0 )->nodeValue ?? '', 'remote episode image' );
			$t->same( '754', $xp->query( 'itunes:duration', $item )->item( 0 )->textContent ?? '' );
		}
		$typed = epm_h_item( $xp, Episodes::get_guid( $mp3 ) );
		$t->same( 'audio/mpeg', $typed ? $xp->query( 'enclosure/@type', $typed )->item( 0 )->nodeValue : 'missing', 'audio/mp3 normalized' );
		$t->same( null, epm_h_item( $xp, Episodes::get_guid( $ogg ) ), 'Ogg is not distributable' );

		foreach ( [ $m4a, $mp3, $ogg ] as $id ) {
			wp_delete_post( $id, true );
		}
	}
);

$t->test(
	'a show that moved here names its own feed as the new address; an explicit new address wins',
	static function ( EPM_Test_Runner $t ) {
		epm_test_with_settings(
			[ 'moved_in' => true ],
			static function () use ( $t ) {
				$xp = epm_test_xpath( epm_test_feed() );
				$t->same( Feed::url(), $xp->query( '/rss/channel/itunes:new-feed-url' )->item( 0 )->textContent ?? '' );
			}
		);
		epm_test_with_settings(
			[
				'moved_in'     => true,
				'new_feed_url' => 'https://new-host.example.test/feed.xml',
			],
			static function () use ( $t ) {
				$xp = epm_test_xpath( epm_test_feed() );
				$t->same( 'https://new-host.example.test/feed.xml', $xp->query( '/rss/channel/itunes:new-feed-url' )->item( 0 )->textContent ?? '' );
			}
		);
		$xp = epm_test_xpath( epm_test_feed() );
		$t->same( 0, $xp->query( '/rss/channel/itunes:new-feed-url' )->length, 'not by default' );
	}
);

$t->test(
	'after a move, readiness warns while imported episodes still load their audio from the old host',
	static function ( EPM_Test_Runner $t ) {
		$count = static function (): ?int {
			foreach ( \EPM\Readiness::report()['checks'] as $check ) {
				if ( 'Audio at the old host' === $check['label'] ) {
					return preg_match( '/(\d+)/', str_replace( [ ',', '.' ], '', $check['message'] ), $m ) ? (int) $m[1] : -1;
				}
			}
			return null;
		};
		$link  = static function (): string {
			foreach ( \EPM\Readiness::report()['checks'] as $check ) {
				if ( 'Audio at the old host' === $check['label'] ) {
					return (string) $check['url'];
				}
			}
			return '';
		};
		$make  = static function ( string $title, array $meta ): int {
			$id = (int) wp_insert_post(
				[
					'post_type'   => 'podcast_episode',
					'post_status' => 'publish',
					'post_title'  => $title,
				]
			);
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, '_epm_' . $key, $value );
			}
			Episodes::clear_data_cache( $id );
			return $id;
		};

		$admin    = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);
		$previous = get_current_user_id();
		wp_set_current_user( (int) $admin[0] );

		$imported = $make(
			'Old host audio',
			[
				'audio_url'    => 'https://old-host.example.test/audio/old-1.mp3',
				'audio_length' => 1000,
				'source'       => 'import',
			]
		);
		$own      = $make(
			'Own CDN audio',
			[
				'audio_url'    => 'https://cdn.example.test/audio/own-1.mp3',
				'audio_length' => 1000,
			]
		);

		try {
			$t->same( null, $count(), 'no warning without a move' );
			epm_test_with_settings(
				[ 'moved_in' => true ],
				static function () use ( $t, $count, $link, $imported ) {
					$before = $count();
					$t->assert( null !== $before && $before >= 1, 'warning after a move' );
					$t->assert( false !== strpos( $link(), 'page=epm-hosting' ), 'links to Hosting & import' );
					wp_delete_post( $imported, true );
					$after = $count();
					$t->same( null === $before ? null : ( 1 === $before ? null : $before - 1 ), $after, 'counts imported episodes only, not audio this site added' );
				}
			);
		} finally {
			foreach ( [ $imported, $own ] as $id ) {
				wp_delete_post( $id, true );
			}
			wp_set_current_user( $previous );
		}
	}
);

$t->test(
	'with another host the site points apps at the host\'s feed and never redirects the feed to itself',
	static function ( EPM_Test_Runner $t ) {
		$host = epm_h_url( 'synthetic/locked-show.xml' );
		try {
			$t->same( '', Hosting::feed_redirect_target(), 'self-hosted' );
			$t->same( Feed::url(), Hosting::public_feed_url() );
			$t->same( 'This website', Hosting::provider_name() );

			epm_h_hosting(
				[
					'mode'     => 'external',
					'provider' => 'buzzsprout',
					'feed_url' => $host,
					'redirect' => true,
					'sync'     => false,
				]
			);
			$t->same( $host, Hosting::feed_redirect_target() );
			$t->same( $host, Hosting::public_feed_url() );
			$t->same( 'Buzzsprout', Hosting::provider_name() );
			ob_start();
			epm()->feed->discovery_link();
			$t->assert( false !== strpos( (string) ob_get_clean(), 'href="' . esc_url( $host ) . '"' ), 'discovery link names the host feed' );

			epm_h_hosting( [ 'redirect' => false ] );
			$t->same( '', Hosting::feed_redirect_target(), 'redirect turned off' );

			$own = Feed::url();
			foreach ( [ $own, untrailingslashit( $own ), set_url_scheme( $own, 'https' ), add_query_arg( Feed::QUERY_VAR, '1', home_url( '/' ) ) ] as $loop ) {
				epm_h_hosting(
					[
						'redirect' => true,
						'feed_url' => $loop,
					]
				);
				$t->same( '', Hosting::feed_redirect_target(), 'self-loop refused: ' . $loop );
			}
		} finally {
			epm_h_restore( Hosting::OPTION );
		}
	}
);

$t->test(
	'download statistics: the measurement prefix wraps enclosures and trailers of this site\'s feed, once, and only when self-hosted',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$local   = (string) wp_get_attachment_url( (int) $fx['audio_1'] );
		$tracked = 'https://op3.dev/e/cdn.example.test/audio/tracked.mp3';
		$trailer = (int) wp_insert_post(
			[
				'post_type'   => 'podcast_episode',
				'post_status' => 'publish',
				'post_title'  => 'Trailer: meet the show',
				'post_date'   => '2026-05-01 10:00:00',
			]
		);
		$already = (int) wp_insert_post(
			[
				'post_type'   => 'podcast_episode',
				'post_status' => 'publish',
				'post_title'  => 'Already measured',
				'post_date'   => '2026-05-02 10:00:00',
			]
		);
		update_post_meta( $trailer, '_epm_audio_url', 'https://cdn.example.test/audio/trailer.mp3' );
		update_post_meta( $trailer, '_epm_audio_length', 4321 );
		update_post_meta( $trailer, '_epm_episode_type', 'trailer' );
		update_post_meta( $trailer, '_epm_season_number', 2 );
		update_post_meta( $already, '_epm_audio_url', $tracked );
		foreach ( [ $trailer, $already ] as $id ) {
			Episodes::get_guid( $id );
			Episodes::clear_data_cache( $id );
		}

		$enclosures = static function (): array {
			$xp   = epm_test_xpath( epm_test_feed() );
			$urls = [];
			foreach ( $xp->query( '/rss/channel/item' ) as $item ) {
				$urls[ $xp->query( 'guid', $item )->item( 0 )->textContent ] = $xp->query( 'enclosure/@url', $item )->item( 0 )->nodeValue;
			}
			foreach ( $xp->query( '/rss/channel/podcast:trailer' ) as $node ) {
				$urls[ 'trailer: ' . $node->textContent ] = $node->getAttribute( 'url' );
			}
			return $urls;
		};
		$ep1 = Episodes::get_guid( (int) $fx['ep1'] );
		$tr  = Episodes::get_guid( $trailer );
		$al  = Episodes::get_guid( $already );

		try {
			$plain = $enclosures();
			$t->same( $local, $plain[ $ep1 ] ?? '', 'no prefix by default' );
			$t->same( 'https://cdn.example.test/audio/trailer.mp3', $plain['trailer: Trailer: meet the show'] ?? '' );

			$cases = [
				'op3'     => 'https://op3.dev/e/',
				'podtrac' => 'https://dts.podtrac.com/redirect.mp3/',
			];
			foreach ( $cases as $service => $prefix ) {
				epm_h_hosting( [ 'stats' => $service ] );
				$t->same( $prefix, Hosting::stats_prefix(), $service );
				$urls = $enclosures();
				$t->same( $prefix . preg_replace( '#^https://#i', '', $local ), $urls[ $ep1 ] ?? '', $service . ': local audio' );
				$t->same( $prefix . 'cdn.example.test/audio/trailer.mp3', $urls[ $tr ] ?? '', $service . ': https:// dropped' );
				$t->same( $prefix . 'cdn.example.test/audio/trailer.mp3', $urls['trailer: Trailer: meet the show'] ?? '', $service . ': podcast:trailer' );
			}
			$t->same( $prefix . 'op3.dev/e/cdn.example.test/audio/tracked.mp3', $urls[ $al ] ?? '', 'another service is chained' );
			epm_h_hosting( [ 'stats' => 'op3' ] );
			$t->same( $tracked, $enclosures()[ $al ] ?? '', 'never the same service twice' );

			epm_h_hosting(
				[
					'stats'        => 'custom',
					'stats_prefix' => 'https://prefix.example.test/p',
				]
			);
			$t->same( 'https://prefix.example.test/p/cdn.example.test/audio/trailer.mp3', $enclosures()[ $tr ] ?? '', 'custom prefix with a trailing slash added' );
			epm_h_hosting(
				[
					'stats'        => 'custom',
					'stats_prefix' => 'http://insecure.example.test/p/',
				]
			);
			$t->same( [ '', '' ], [ Hosting::get( 'stats' ), Hosting::stats_prefix() ], 'a custom prefix must be https' );

			epm_h_hosting(
				[
					'stats'    => 'op3',
					'mode'     => 'external',
					'feed_url' => epm_h_url( 'synthetic/locked-show.xml' ),
					'sync'     => false,
				]
			);
			$t->same( 'https://cdn.example.test/audio/trailer.mp3', Hosting::measured_url( 'https://cdn.example.test/audio/trailer.mp3' ), 'the host measures its own feed' );
		} finally {
			epm_h_restore( Hosting::OPTION );
			wp_delete_post( $trailer, true );
			wp_delete_post( $already, true );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'podcast:trailer at channel level for published trailers with distributable audio; podcast:person for host and guest',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$make = static function ( string $title, string $audio, int $season, string $date, string $status = 'publish' ): int {
			$id = (int) wp_insert_post(
				[
					'post_type'   => 'podcast_episode',
					'post_status' => $status,
					'post_title'  => $title,
					'post_date'   => $date,
				]
			);
			update_post_meta( $id, '_epm_audio_url', $audio );
			update_post_meta( $id, '_epm_episode_type', 'trailer' );
			if ( $season > 0 ) {
				update_post_meta( $id, '_epm_season_number', $season );
			}
			Episodes::get_guid( $id );
			Episodes::clear_data_cache( $id );
			return $id;
		};
		$show  = $make( 'Show trailer & more', 'https://cdn.example.test/audio/show-trailer.m4a', 0, '2026-04-02 10:00:00' );
		$s2    = $make( 'Season 2 trailer', 'https://cdn.example.test/audio/s2-trailer.mp3', 2, '2026-04-01 10:00:00' );
		$ogg   = $make( 'Ogg trailer', 'https://cdn.example.test/audio/trailer.ogg', 0, '2026-03-31 10:00:00' );
		$draft = $make( 'Draft trailer', 'https://cdn.example.test/audio/draft.mp3', 0, '2026-03-30 10:00:00', 'draft' );
		$image = get_post_meta( (int) $fx['ep1'], '_epm_guest_image_id', true );

		try {
			$xp       = epm_test_xpath( epm_test_feed() );
			$trailers = [];
			foreach ( $xp->query( '/rss/channel/podcast:trailer' ) as $node ) {
				$trailers[ $node->textContent ] = [ $node->getAttribute( 'url' ), $node->getAttribute( 'type' ), $node->getAttribute( 'season' ), $node->getAttribute( 'pubdate' ) ];
			}
			$mine     = array_values( array_intersect( array_keys( $trailers ), [ 'Show trailer & more', 'Season 2 trailer', 'Ogg trailer', 'Draft trailer' ] ) );
			$t->same( [ 'Show trailer & more', 'Season 2 trailer' ], $mine, 'published, distributable trailers only, newest first' );
			$t->same( [ 'https://cdn.example.test/audio/show-trailer.m4a', 'audio/x-m4a', '' ], array_slice( $trailers['Show trailer & more'] ?? [], 0, 3 ) );
			$t->same( [ 'https://cdn.example.test/audio/s2-trailer.mp3', 'audio/mpeg', '2' ], array_slice( $trailers['Season 2 trailer'] ?? [], 0, 3 ) );
			$t->same( strtotime( '2026-04-01 10:00:00 ' . wp_timezone_string() ), strtotime( (string) ( $trailers['Season 2 trailer'][3] ?? '' ) ), 'pubdate (RFC 2822)' );
			$item = epm_h_item( $xp, Episodes::get_guid( $s2 ) );
			$t->same( 'trailer', $item ? $xp->query( 'itunes:episodeType', $item )->item( 0 )->textContent : 'missing', 'the item is typed as a trailer too' );

			$t->same( 0, $xp->query( '/rss/channel/podcast:person' )->length, 'no host set: no channel person' );
			$ep1 = epm_h_item( $xp, Episodes::get_guid( (int) $fx['ep1'] ) );
			$guest = $ep1 ? $xp->query( 'podcast:person', $ep1 )->item( 0 ) : null;
			$t->same( [ 'guest', 'Max Guest', '' ], $guest ? [ $guest->getAttribute( 'role' ), $guest->textContent, $guest->getAttribute( 'img' ) ] : [], 'guest without a photo' );

			update_post_meta( (int) $fx['ep1'], '_epm_guest_image_id', (int) $fx['square_image'] );
			Episodes::clear_data_cache( (int) $fx['ep1'] );
			epm_test_with_settings(
				[ 'host' => 'Hana <b>Host</b> & Co' ],
				static function () use ( $t, $fx ) {
					$xp   = epm_test_xpath( epm_test_feed() );
					$host = $xp->query( '/rss/channel/podcast:person' );
					$t->same( 1, $host->length );
					$t->same( [ 'host', 'Hana Host & Co' ], $host->length ? [ $host->item( 0 )->getAttribute( 'role' ), $host->item( 0 )->textContent ] : [], 'host, tags removed' );
					$ep1 = epm_h_item( $xp, Episodes::get_guid( (int) $fx['ep1'] ) );
					$img = $ep1 ? (string) $xp->query( 'podcast:person/@img', $ep1 )->item( 0 )->nodeValue : '';
					$t->assert( false !== strpos( $img, '/wp-content/uploads/' ), 'guest photo: ' . $img );
				}
			);
		} finally {
			update_post_meta( (int) $fx['ep1'], '_epm_guest_image_id', $image );
			Episodes::clear_data_cache( (int) $fx['ep1'] );
			foreach ( [ $show, $s2, $ogg, $draft ] as $id ) {
				wp_delete_post( $id, true );
			}
			Feed::flush_cache();
		}
	}
);

$t->test(
	'a control character in an episode field never breaks the feed',
	static function ( EPM_Test_Runner $t ) {
		$dirty = static function ( $data ) {
			$data['title']             = "Our\x08 guest";
			$data['short_description'] = "A pasted\x0B summary\x1F.";
			$data['guest_name']        = "Gia\x07 Guest";
			$data['show_notes']        = "Notes with a \x0C form feed.";
			return $data;
		};
		add_filter( 'epm_feed_episode', $dirty );
		try {
			Feed::flush_cache();
			$xml = epm()->feed->get_document()['xml'];
			$t->assert( false !== simplexml_load_string( $xml ), 'the feed is well-formed XML' );
			$t->assert( false !== strpos( $xml, '<title>Our guest</title>' ), 'the text itself stays' );
			$t->assert( 1 !== preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $xml ), 'no control characters left' );
		} finally {
			remove_filter( 'epm_feed_episode', $dirty );
			Feed::flush_cache();
		}
		$t->same( 'a &amp; b', epm_esc_xml( "a\x01 &\x1F b" ) );
		$t->same( '<![CDATA[<p>x</p>]]>', Feed::cdata( "<p>x\x08</p>" ) );
	}
);

$t->test(
	'Last-Modified and lastBuildDate move when the feed changes without a new episode (channel settings)',
	static function ( EPM_Test_Runner $t ) {
		$settings = get_option( PodcastSettings::OPTION );
		try {
			Feed::flush_cache();
			epm()->feed->get_document();
			// Pretend the current feed was built long ago.
			$built             = get_option( Feed::BUILD_OPTION );
			$built['modified'] = time() - 30 * DAY_IN_SECONDS;
			update_option( Feed::BUILD_OPTION, $built, false );
			Feed::flush_cache();
			$same   = epm()->feed->get_document();
			$newest = 0;
			foreach ( epm()->feed->eligible_episodes() as $post ) {
				$newest = max( $newest, (int) get_post_modified_time( 'U', true, $post ), (int) get_post_time( 'U', true, $post ) );
			}
			$t->same( max( $newest, $built['modified'] ), $same['modified'], 'unchanged content keeps its time (not the time of the rebuild)' );

			$start = time();
			update_option( PodcastSettings::OPTION, array_merge( (array) $settings, [ 'copyright' => '© Changed ' . wp_generate_password( 6, false ) ] ) );
			$changed = epm()->feed->get_document();
			$t->assert( $changed['modified'] >= $start, 'a channel change moves Last-Modified' );
			$t->assert( false !== strpos( $changed['xml'], '<lastBuildDate>' . gmdate( 'D, d M Y H:i:s', $changed['modified'] ) . ' +0000</lastBuildDate>' ), 'lastBuildDate follows' );
			$t->same( $changed['modified'], (int) get_option( Feed::BUILD_OPTION )['modified'], 'remembered' );

			Feed::flush_cache();
			$again = epm()->feed->get_document();
			$t->same( [ $changed['modified'], $changed['etag'] ], [ $again['modified'], $again['etag'] ], 'a rebuild of the same content keeps both' );
		} finally {
			update_option( PodcastSettings::OPTION, $settings );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'an M4A file in the Media Library is published as audio/x-m4a and described as M4A',
	static function ( EPM_Test_Runner $t ) {
		$m4a = epm_h_attachment( 'episode-12.m4a', EPM_Test_HTTP::mp3( 1 ), 'audio/mpeg' );
		$mp3 = epm_h_attachment( 'episode-13.mp3', EPM_Test_HTTP::mp3( 1 ), 'audio/mpeg' );
		$id  = (int) wp_insert_post(
			[
				'post_type'   => EpisodePostType::CPT,
				'post_status' => 'publish',
				'post_title'  => 'M4A from the Media Library',
				'meta_input'  => [ '_epm_audio_id' => $m4a ],
			]
		);
		try {
			$t->same( 'audio/x-m4a', AudioMetadata::attachment_mime( $m4a ), 'WordPress files .m4a as audio/mpeg' );
			$t->same( 'audio/mpeg', AudioMetadata::attachment_mime( $mp3 ) );
			$t->same( 'audio/x-m4a', AudioMetadata::describe( $m4a )['mime'] );
			Episodes::clear_data_cache( $id );
			$data = epm()->episodes->get_data( $id );
			$t->same( 'audio/x-m4a', $data['audio_mime'] ?? '' );
			$t->same( 'audio/x-m4a', AudioMetadata::enclosure( $data )['type'] );
			$t->same( 'audio/x-m4a', AudioMetadata::mime_from_url( 'https://cdn.example.test/book.m4b' ) );
		} finally {
			wp_delete_post( $id, true );
			wp_delete_attachment( $m4a, true );
			wp_delete_attachment( $mp3, true );
			Feed::flush_cache();
		}
	}
);

$t->test(
	'the delivery check only makes safe requests: an author\'s audio URL cannot reach private addresses',
	static function ( EPM_Test_Runner $t ) {
		$id      = (int) wp_insert_post(
			[
				'post_type'   => EpisodePostType::CPT,
				'post_status' => 'publish',
				'post_title'  => 'Metadata probe',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'meta_input'  => [ '_epm_audio_url' => 'http://169.254.169.254/latest/meta-data/' ],
			]
		);
		$seen    = [];
		$capture = static function ( $pre, $args, $url ) use ( &$seen ) {
			$seen[] = [ $url, ! empty( $args['reject_unsafe_urls'] ) ];
			// Nothing leaves the machine: answer like a working server.
			if ( 'HEAD' === ( $args['method'] ?? '' ) ) {
				return EPM_Test_HTTP::response( 200, '', [ 'content-length' => '1000', 'content-type' => 'audio/mpeg' ] );
			}
			if ( isset( $args['headers']['Range'] ) ) {
				return EPM_Test_HTTP::response( 206, 'ab', [ 'content-type' => 'audio/mpeg' ] );
			}
			return EPM_Test_HTTP::response( 200, '<?xml version="1.0"?><rss version="2.0"></rss>', [ 'content-type' => 'application/rss+xml' ] );
		};
		add_filter( 'pre_http_request', $capture, 1, 3 );
		try {
			AdminPages::server_check();
			$t->same( [ Hosting::public_feed_url(), 'http://169.254.169.254/latest/meta-data/', 'http://169.254.169.254/latest/meta-data/' ], array_column( $seen, 0 ), 'the feed, then the newest episode\'s audio (HEAD, range)' );
			$t->same( [ true, true, true ], array_column( $seen, 1 ), 'every request rejects unsafe addresses' );
		} finally {
			remove_filter( 'pre_http_request', $capture, 1 );
			wp_delete_post( $id, true );
			Feed::flush_cache();
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Transcript files' );
/* ------------------------------------------------------------------------- */

$t->test(
	'SRT files are stored as application/x-subrip on every server, only where the site accepts .srt',
	static function ( EPM_Test_Runner $t ) {
		$srt = wp_tempnam( 'captions.srt' );
		file_put_contents( $srt, "1\n00:00:00,000 --> 00:00:02,000\nHello.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$blank = [
			'ext'             => false,
			'type'            => false,
			'proper_filename' => false,
		];

		try {
			// End to end, whatever this server's libmagic reports.
			$result = wp_check_filetype_and_ext( $srt, 'captions.srt' );
			$t->same( [ 'srt', 'application/x-subrip' ], [ $result['ext'], $result['type'] ], 'upload check' );

			// libmagic reporting text/plain: WordPress accepts it as text/plain.
			$t->same(
				[ 'ext' => 'srt', 'type' => 'application/x-subrip', 'proper_filename' => false ],
				Transcripts::check_filetype( [ 'ext' => 'srt', 'type' => 'text/plain', 'proper_filename' => false ], $srt, 'captions.srt', null, 'text/plain' ),
				'text/plain detection'
			);
			// Newer libmagic reporting application/x-subrip: WordPress rejects it.
			$t->same(
				[ 'ext' => 'srt', 'type' => 'application/x-subrip', 'proper_filename' => false ],
				Transcripts::check_filetype( $blank, $srt, 'captions.srt', null, 'application/x-subrip' ),
				'application/x-subrip detection'
			);

			// A site that does not accept .srt keeps rejecting it.
			$t->same( $blank, Transcripts::check_filetype( $blank, $srt, 'captions.srt', [ 'vtt' => 'text/vtt' ], 'application/x-subrip' ), 'site without .srt' );
			// Other files are untouched; a detected binary is not an SRT.
			$t->same( [ 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => false ], Transcripts::check_filetype( [ 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => false ], $srt, 'a.png', null, 'image/png' ) );
			$t->same( $blank, Transcripts::check_filetype( $blank, $srt, 'fake.srt', null, 'application/x-dosexec' ), 'binary named .srt' );

			$t->same( 'text/vtt', get_allowed_mime_types()['vtt'] ?? '', 'WebVTT is a WordPress type already' );
		} finally {
			wp_delete_file( $srt );
		}
	}
);

$t->test(
	'transcript types: aliases normalized, missing types taken from the address',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			[ 'application/srt', '', 'application/x-subrip' ],
			[ 'text/srt', '', 'application/x-subrip' ],
			[ 'text/x-srt', '', 'application/x-subrip' ],
			[ 'Text/X-SubRip', '', 'application/x-subrip' ],
			[ 'text/vtt; charset=utf-8', '', 'text/vtt' ],
			[ 'text/webvtt', '', 'text/vtt' ],
			[ 'text/json', '', 'application/json' ],
			[ 'application/json', '', 'application/json' ],
			[ 'text/html', '', 'text/html' ],
			[ '', 'https://cdn.example.test/t/ep.VTT?sig=1', 'text/vtt' ],
			[ 'application/octet-stream', 'https://cdn.example.test/t/ep.srt', 'application/x-subrip' ],
			[ 'application/octet-stream', 'https://cdn.example.test/t/ep', '' ],
			[ 'video/mp4', '', '' ],
			[ '', '', '' ],
		];
		foreach ( $cases as [ $type, $url, $expected ] ) {
			$t->same( $expected, Transcripts::normalize_type( $type, $url ), $type . ' ' . $url );
		}
		$names = [
			'captions.srt'                          => 'application/x-subrip',
			'/var/uploads/2026/09/ep.vtt'           => 'text/vtt',
			'https://cdn.example.test/a.json?x=1.srt' => 'application/json',
			'page.htm'                              => 'text/html',
			'notes.txt'                             => 'text/plain',
			'audio.mp3'                             => '',
			'no-extension'                          => '',
		];
		foreach ( $names as $name => $expected ) {
			$t->same( $expected, Transcripts::mime( $name ), $name );
		}
	}
);

$t->test(
	'an episode lists its uploaded transcript before a hosted one, never two of one type',
	static function ( EPM_Test_Runner $t ) {
		$vtt = epm_h_attachment( 'epm-h-captions.vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\n<v Sam>Hi there.\n\n00:02.000 --> 00:04.000\n<v Sam>Welcome.\n\n00:04.000 --> 00:06.000\n<v Gia>Thanks.\n", 'text/vtt' );
		$id  = (int) wp_insert_post(
			[
				'post_type'   => 'podcast_episode',
				'post_status' => 'publish',
				'post_title'  => 'Transcript files',
			]
		);
		try {
			$t->same( [], Transcripts::files( $id ), 'none' );

			update_post_meta( $id, '_epm_transcript_url', 'https://cdn.example.test/t/ep.vtt' );
			update_post_meta( $id, '_epm_transcript_type', '' );
			$t->same( [ [ 'url' => 'https://cdn.example.test/t/ep.vtt', 'type' => 'text/vtt' ] ], Transcripts::files( $id ), 'hosted, type from the address' );

			update_post_meta( $id, '_epm_transcript_file_id', $vtt );
			$t->same( [ [ 'url' => wp_get_attachment_url( $vtt ), 'type' => 'text/vtt' ] ], Transcripts::files( $id ), 'the upload wins over a hosted file of the same type' );

			update_post_meta( $id, '_epm_transcript_url', 'https://cdn.example.test/t/ep-transcript' );
			update_post_meta( $id, '_epm_transcript_type', 'application/srt' );
			$t->same(
				[
					[ 'url' => wp_get_attachment_url( $vtt ), 'type' => 'text/vtt' ],
					[ 'url' => 'https://cdn.example.test/t/ep-transcript', 'type' => 'application/x-subrip' ],
				],
				Transcripts::files( $id ),
				'another type is listed after the upload'
			);

			update_post_meta( $id, '_epm_transcript_type', 'video/mp4' );
			$t->same( 1, count( Transcripts::files( $id ) ), 'an unknown type without an extension is dropped' );

			update_post_meta( $id, '_epm_transcript_file_id', (int) get_option( 'epm_test_fixtures' )['ep2'] );
			delete_post_meta( $id, '_epm_transcript_url' );
			$t->same( [], Transcripts::files( $id ), 'a file ID that is no attachment is ignored' );

			update_post_meta( $id, '_epm_transcript_file_id', $vtt );
			Episodes::clear_data_cache( $id );
			$t->same( Transcripts::files( $id ), epm()->episodes->get_data( $id )['transcript_files'] ?? null, 'episode data carries the files' );

			$filter = static function ( $files ) {
				$files[] = [
					'url'  => 'https://cdn.example.test/t/extra.json',
					'type' => 'application/json',
				];
				return $files;
			};
			add_filter( 'epm_transcript_files', $filter );
			$t->same( 2, count( Transcripts::files( $id ) ), 'epm_transcript_files filter' );
			remove_filter( 'epm_transcript_files', $filter );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

$t->test(
	'readable text from an uploaded transcript file',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$srt   = epm_h_attachment( 'epm-h-talk.srt', "1\r\n00:00:00,000 --> 00:00:02,000\r\nSam: One.\r\n\r\n2\r\n00:00:02,000 --> 00:00:04,000\r\nSam: Two <i>&amp;</i> three.\r\n\r\n3\r\n00:00:04,000 --> 00:00:06,000\r\nGia: Four.\r\n", 'application/x-subrip' );
		$vtt   = epm_h_attachment( 'epm-h-talk.vtt', "WEBVTT\n\nNOTE skipped\n\n00:00.000 --> 00:02.000\n<v Sam>One.\n", 'text/vtt' );
		$empty = epm_h_attachment( 'epm-h-empty.vtt', "  \n", 'text/vtt' );
		$big   = epm_h_attachment( 'epm-h-big.vtt', "WEBVTT\n\n" . str_repeat( "00:00.000 --> 00:02.000\nWords words words.\n\n", 60000 ), 'text/vtt' );

		$t->same( "<p><strong>Sam:</strong> One. Two &amp; three.</p>\n<p><strong>Gia:</strong> Four.</p>", Transcripts::html_from_attachment( $srt ), 'SRT with Windows line ends' );
		$t->same( '<p><strong>Sam:</strong> One.</p>', Transcripts::html_from_attachment( $vtt ), 'WebVTT' );
		$t->same( '', Transcripts::html_from_attachment( $empty ), 'empty file' );
		$t->same( '', Transcripts::html_from_attachment( $big ), 'larger than 2 MB' );
		$t->same( '', Transcripts::html_from_attachment( (int) $fx['artwork'] ), 'an image is no transcript' );
		$t->same( '', Transcripts::html_from_attachment( 0 ), 'no attachment' );

		wp_delete_file( (string) get_attached_file( $vtt ) );
		$t->same( '', Transcripts::html_from_attachment( $vtt ), 'file missing on disk' );
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Setup assistant and distribution' );
/* ------------------------------------------------------------------------- */

$t->test(
	'setup steps save the hosting path, host, show details, style and a podcast page',
	static function ( EPM_Test_Runner $t ) {
		$pages = new AdminPages();
		try {
			$t->same( 'epm_setup_path', ( static function ( $r ) {
				return is_wp_error( $r ) ? $r->get_error_code() : 'saved';
			} )( $pages->save_step( 'path', [ 'path' => 'somewhere' ] ) ) );
			// Choosing a path switches nothing yet: another host needs its
			// feed first (next step). Only "host it here" switches back.
			epm_h_hosting( [ 'mode' => 'self' ] );
			$t->same( [ 'path' => 'external' ], $pages->save_step( 'path', [ 'path' => 'external' ] ) );
			$t->same( [ 'external', 'self', '' ], [ AdminPages::setup_state()['path'], Hosting::get( 'mode' ), Hosting::feed_redirect_target() ], 'external chosen, no feed yet' );
			epm_h_hosting(
				[
					'mode'     => 'external',
					'feed_url' => 'https://feeds.example.test/old.xml',
				]
			);
			$pages->save_step( 'path', [ 'path' => 'move' ] );
			$t->same( [ 'move', 'external' ], [ AdminPages::setup_state()['path'], Hosting::get( 'mode' ) ], 'move chosen: unchanged until the host step' );
			EPM_Test_HTTP::$routes['https://feeds.example.test/old.xml'] = static fn () => EPM_Test_HTTP::response( 200, epm_h_sync_feed( [ 1 ] ), [ 'content-type' => 'application/rss+xml' ] );
			$pages->save_step( 'hosting', [ 'feed_url' => 'https://feeds.example.test/old.xml', 'provider' => 'buzzsprout', 'mode' => 'external' ] );
			$t->same( 'self', Hosting::get( 'mode' ), 'moving: the host step keeps the show here' );
			epm_h_hosting( [ 'mode' => 'external' ] );
			$pages->save_step( 'path', [ 'path' => 'new' ] );
			$t->same( [ 'new', 'self' ], [ AdminPages::setup_state()['path'], Hosting::get( 'mode' ) ], 'new: hosted here' );

			$pages->save_step( 'path', [ 'path' => 'external' ] );
			EPM_Test_HTTP::$routes['https://feeds.example.test/x.xml'] = static fn () => EPM_Test_HTTP::response( 200, epm_h_sync_feed( [ 1 ] ), [ 'content-type' => 'application/rss+xml' ] );
			EPM_Test_HTTP::$routes['https://feeds.example.test/bad.xml'] = static fn () => EPM_Test_HTTP::response( 200, '<html>not a feed</html>', [ 'content-type' => 'text/html' ] );
			$rejected = $pages->save_step( 'hosting', [ 'feed_url' => 'https://feeds.example.test/bad.xml', 'provider' => 'buzzsprout', 'mode' => 'external' ] );
			$t->assert( is_wp_error( $rejected ), 'an unparseable feed cannot switch hosting' );
			$saved = $pages->save_step(
				'hosting',
				[
					'mode'       => 'external',
					'feed_url'   => 'feed://feeds.example.test/x.xml',
					'provider'   => 'buzzsprout',
					'sync'       => '1',
					'redirect'   => '',
					'new_status' => 'draft',
					'unknown'    => 'dropped',
				]
			);
			$hosting = Hosting::all();
			$t->same( [ 'external', 'https://feeds.example.test/x.xml', 'buzzsprout', true, false, 'draft' ], [ $hosting['mode'], $hosting['feed_url'], $hosting['provider'], $hosting['sync'], $hosting['redirect'], $hosting['new_status'] ] );
			$t->assert( ! isset( get_option( Hosting::OPTION )['unknown'] ), 'unknown keys dropped' );
			$t->same( $hosting, $saved['hosting'] ?? null );
			unset( EPM_Test_HTTP::$routes['https://feeds.example.test/old.xml'], EPM_Test_HTTP::$routes['https://feeds.example.test/x.xml'], EPM_Test_HTTP::$routes['https://feeds.example.test/bad.xml'] );

			$links = epm()->settings->get( 'platform_links' );
			$show  = $pages->save_step(
				'show',
				[
					'title'       => 'Setup Title',
					'category'    => 'Business::Marketing',
					'owner_email' => 'not an email',
					'locked'      => '1',
				]
			);
			$now = epm()->settings->all();
			$t->same( [ 'Setup Title', 'Business', 'Marketing', '', false ], [ $now['title'], $now['category'], $now['subcategory'], $now['owner_email'], $now['locked'] ] );
			// The settings sanitizer may reorder the keys of each link.
			$t->assert( $links == $now['platform_links'], 'other settings kept: ' . wp_json_encode( $now['platform_links'] ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same links, key order aside.
			$t->assert( isset( $show['settings'], $show['artwork'], $show['readiness'] ), 'response parts' );
			$pages->save_step( 'show', [ 'category' => 'Technology' ] );
			$t->same( [ 'Technology', '' ], [ epm()->settings->get( 'category' ), epm()->settings->get( 'subcategory' ) ], 'a new category resets the subcategory' );

			$current = (string) epm()->design->get( 'preset' );
			$preset  = '';
			foreach ( array_keys( epm()->presets->all() ) as $id ) {
				if ( $id !== $current ) {
					$preset = (string) $id;
					break;
				}
			}
			$design = $pages->save_step(
				'design',
				[
					'preset'      => $preset,
					'create_page' => '1',
				]
			);
			$t->same( $preset, $design['preset'] ?? null, 'preset applied' );
			$page = (int) ( $design['page']['id'] ?? 0 );
			$t->assert( $page > 0 && 'publish' === get_post_status( $page ), 'podcast page published' );
			$t->same( 'Setup Title', get_the_title( $page ) );
			$content = (string) get_post_field( 'post_content', $page );
			foreach ( [ '[podcast_latest]', '[podcast_subscribe]', '[podcast_episodes]' ] as $shortcode ) {
				$t->assert( false !== strpos( $content, $shortcode ), $shortcode );
			}
			$t->same( $page, AdminPages::setup_state()['page_id'] );
			$again = $pages->save_step(
				'design',
				[
					'preset'      => 'no-such-preset',
					'create_page' => '1',
				]
			);
			$t->same( [ $page, $preset ], [ (int) ( $again['page']['id'] ?? 0 ), $again['preset'] ?? '' ], 'one page; unknown presets ignored' );
			wp_trash_post( $page );
			$third = $pages->save_step( 'design', [ 'create_page' => '1' ] );
			$t->assert( (int) ( $third['page']['id'] ?? 0 ) > 0 && (int) $third['page']['id'] !== $page, 'a trashed page is replaced' );

			$t->assert( AdminPages::needs_setup() === false, 'configured site' );
			$done = $pages->save_step( 'finish', [] );
			$t->same( true, $done['done'] ?? null );
			$t->same( true, AdminPages::setup_state()['done'] );

			$unknown = $pages->save_step( 'nope', [] );
			$t->same( 'epm_setup_step', is_wp_error( $unknown ) ? $unknown->get_error_code() : 'saved' );
		} finally {
			foreach ( [ AdminPages::SETUP_OPTION, Hosting::OPTION, PodcastSettings::OPTION, 'epm_design_settings' ] as $option ) {
				epm_h_restore( $option );
			}
		}
	}
);

$t->test(
	'the setup assistant is offered only on a site without a podcast',
	static function ( EPM_Test_Runner $t ) {
		try {
			delete_option( AdminPages::SETUP_OPTION );
			$t->same( false, AdminPages::needs_setup(), 'configured' );
			delete_option( PodcastSettings::OPTION );
			$t->same( true, AdminPages::needs_setup(), 'fresh' );
			AdminPages::update_setup_state( [ 'dismissed' => true ] );
			$t->same( false, AdminPages::needs_setup(), 'dismissed' );
			update_option( AdminPages::SETUP_OPTION, [ 'done' => true ] );
			$t->same( false, AdminPages::needs_setup(), 'done' );
		} finally {
			epm_h_restore( PodcastSettings::OPTION );
			epm_h_restore( AdminPages::SETUP_OPTION );
		}
	}
);

$t->test(
	'artwork is checked against directory rules',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$t->same( false, AdminPages::artwork_check( 0 )['ok'] );
		$square = AdminPages::artwork_check( (int) $fx['artwork'] );
		$t->same( [ true, 1400, 1400 ], [ $square['ok'], $square['width'], $square['height'] ] );
		$wide = AdminPages::artwork_check( (int) $fx['wide_image'] );
		$t->same( false, $wide['ok'] );
		$t->same( 2, count( $wide['messages'] ), 'not square, too small' );
	}
);

$t->test(
	'distribution progress: a listing link becomes a subscribe link once',
	static function ( EPM_Test_Runner $t ) {
		$count = static function ( string $service ): int {
			return count(
				array_filter(
					(array) epm()->settings->get( 'platform_links' ),
					static function ( $link ) use ( $service ) {
						return ( $link['service'] ?? '' ) === $service;
					}
				)
			);
		};
		try {
			$t->same( [ 'status' => '', 'url' => '' ], Directories::save_progress( 'no-such-platform', 'listed', 'https://example.com/' ) );
			$t->same( [ 'status' => 'submitted', 'url' => '' ], Directories::save_progress( 'pocketcasts', 'submitted', '' ) );
			$t->same( 0, $count( 'pocketcasts' ), 'no link before listing' );
			$t->same( [ 'status' => 'listed', 'url' => 'https://pca.st/itunes/test-show' ], Directories::save_progress( 'pocketcasts', '', 'https://pca.st/itunes/test-show' ) );
			Directories::save_progress( 'pocketcasts', 'listed', 'https://pca.st/itunes/test-show' );
			$t->same( 1, $count( 'pocketcasts' ), 'added once' );
			$t->same( [ 'status' => 'listed', 'url' => 'https://pca.st/itunes/test-show' ], Directories::progress()['pocketcasts'] ?? [] );

			$spotify = array_values(
				array_filter(
					(array) epm()->settings->get( 'platform_links' ),
					static function ( $link ) {
						return 'spotify' === ( $link['service'] ?? '' );
					}
				)
			);
			Directories::save_progress( 'spotify', 'listed', 'https://open.spotify.com/show/other' );
			$t->same( 1, $count( 'spotify' ), 'an existing Spotify link is not doubled' );
			$t->same( 'https://open.spotify.com/show/example', $spotify[0]['url'] ?? '', 'nor replaced' );

			Directories::save_progress( 'podcastde', 'listed', 'https://www.podcast.de/podcast/123/' );
			Directories::save_progress( 'podcastde', 'listed', 'https://www.podcast.de/podcast/123' );
			$t->same( 1, count( array_filter( (array) epm()->settings->get( 'platform_links' ), static function ( $link ) {
				return 0 === strpos( (string) $link['url'], 'https://www.podcast.de/podcast/123' );
			} ) ), 'custom links deduplicated by address' );

			wp_set_current_user( 0 );
			$t->assert( false !== strpos( do_shortcode( '[podcast_subscribe]' ), 'https://pca.st/itunes/test-show' ), 'subscribe buttons show the listing' );

			Directories::save_progress( 'pocketcasts', '', '' );
			$t->assert( ! isset( Directories::progress()['pocketcasts'] ), 'progress cleared' );
		} finally {
			epm_h_restore( Directories::OPTION );
			epm_h_restore( PodcastSettings::OPTION );
		}
	}
);

$t->test(
	'credentialed feed URLs are never stored on imported episodes',
	static function ( EPM_Test_Runner $t ) {
		$url = 'https://user:secret@feeds.example.test/private.xml?token=top-secret';
		$importer = new Importer( [ 'feed_url' => $url ] );
		$outcome = $importer->import_item(
			[
				'guid' => 'private-source-probe',
				'title' => 'Private source probe',
				'pub_date' => time() - HOUR_IN_SECONDS,
				'html' => '',
				'audio_url' => 'https://media.example.test/private.mp3',
				'audio_type' => 'audio/mpeg',
			]
		);
		$id = (int) ( $outcome['id'] ?? 0 );
		$t->assert( $id > 0, 'episode imported' );
		$source = (string) get_post_meta( $id, Episodes::META_PREFIX . 'source_feed', true );
		$t->assert( '' !== $source && false === strpos( $source, 'secret' ) && false === strpos( $source, 'token' ), 'metadata contains no URL secrets' );
		$t->same( 'private:' . hash( 'sha256', $url ), $source, 'stable private feed identifier' );
		$t->same( 0, $importer->draft_missing( [ 'private-source-probe' => true ], 0 ), 'private source episodes remain discoverable without the URL' );
		// Simulate a 1.3.0 row, written before the new metadata sanitizer.
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->postmeta, [ 'meta_value' => $url ], [ 'post_id' => $id, 'meta_key' => Episodes::META_PREFIX . 'source_feed' ] );
		wp_cache_delete( $id, 'post_meta' );
		$previous_user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . EpisodePostType::CPT . '/' . $id ) );
			$t->same( 200, $response->get_status(), 'the public legacy episode remains readable' );
			$t->same( $source, $response->get_data()['meta'][ Episodes::META_PREFIX . 'source_feed' ] ?? '', 'REST never reveals legacy source-feed credentials' );
			$t->same( $url, get_post_meta( $id, Episodes::META_PREFIX . 'source_feed', true ), 'reading REST does not rewrite stored legacy data' );
		} finally {
			wp_set_current_user( $previous_user );
		}
		update_post_meta( $id, Episodes::META_PREFIX . 'missing_since', time() - 2 * DAY_IN_SECONDS );
		$t->same( 1, $importer->draft_missing( [ 'another-guid' => true ], 0 ), 'missing episodes are found using both current and legacy private feed identifiers' );
		$t->same( 'draft', get_post_status( $id ), 'the missing private-feed episode is drafted' );
		$moved_url = 'https://feeds.example.test/private.xml?token=rotated-secret';
		$moved_importer = new Importer( [ 'feed_url' => $moved_url ] );
		$moved = $moved_importer->import_item( [ 'guid' => 'private-source-probe', 'title' => 'Private source probe', 'pub_date' => time() - HOUR_IN_SECONDS, 'html' => '', 'audio_url' => 'https://media.example.test/private.mp3', 'audio_type' => 'audio/mpeg' ] );
		$t->same( $id, (int) $moved['id'], 'a private feed move keeps the episode identity' );
		$t->same( 'private:' . hash( 'sha256', $moved_url ), get_post_meta( $id, Episodes::META_PREFIX . 'source_feed', true ), 're-tagging an existing episode never stores the new URL secret' );
		wp_delete_post( $id, true );
	}
);

$t->test(
	'uninstalling with data deletion removes the topics and their relationships (the plugin is not loaded then)',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		$episode = (int) wp_insert_post(
			[
				'post_type'   => EpisodePostType::CPT,
				'post_status' => 'publish',
				'post_title'  => 'Uninstall probe',
			]
		);
		wp_trash_post( $episode );
		$term    = wp_insert_term( 'Uninstall probe ' . wp_generate_password( 6, false ), EpisodePostType::TOPIC );
		$term_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
		$tt_id   = is_wp_error( $term ) ? 0 : (int) $term['term_taxonomy_id'];
		wp_set_object_terms( $episode, [ $term_id ], EpisodePostType::TOPIC );
		$t->assert( $term_id > 0 && has_term( $term_id, EpisodePostType::TOPIC, $episode ), 'probe topic assigned' );

		// Everything uninstall.php deletes is kept aside and put back; only
		// the probe episode and topic are offered to it.
		$options = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'epm%'", ARRAY_A );
		$rewrite_rules = get_option( 'rewrite_rules', false );
		update_option( 'rewrite_rules', [ 'podcast/feed/?$' => 'index.php?epm_feed=1' ] );
		$posts   = static function ( $pre, $query ) use ( $episode ) {
			return EpisodePostType::CPT === $query->get( 'post_type' ) && get_post( $episode ) ? [ $episode ] : $pre;
		};
		// Only the "all topics" query (not term lookups WordPress makes).
		$terms   = static function ( $pre, $query ) use ( $term_id ) {
			$vars = $query->query_vars;
			$all  = in_array( EpisodePostType::TOPIC, (array) ( $vars['taxonomy'] ?? [] ), true ) && 'ids' === ( $vars['fields'] ?? '' ) && empty( $vars['object_ids'] ) && empty( $vars['include'] ) && empty( $vars['name'] ) && empty( $vars['slug'] );
			return $all ? [ $term_id ] : $pre;
		};
		add_filter( 'posts_pre_query', $posts, 10, 2 );
		add_filter( 'terms_pre_query', $terms, 10, 2 );
		add_filter( 'epm_delete_data_on_uninstall', '__return_true' );
		unregister_taxonomy( EpisodePostType::TOPIC );
		try {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'elementor-podcast-manager/elementor-podcast-manager.php' );
			}
			( static function () {
				include dirname( __DIR__, 2 ) . '/uninstall.php';
			} )();

			$t->same( null, get_post( $episode ), 'episode deleted' );
			$t->same( false, get_option( 'rewrite_rules', false ), 'LIFE-N3: uninstall invalidates the podcast routes' );
			$t->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d OR term_taxonomy_id = %d", $episode, $tt_id ) ), 'no topic relationships left' );
			$t->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) ), 'topic deleted' );
		} finally {
			remove_filter( 'posts_pre_query', $posts, 10 );
			remove_filter( 'terms_pre_query', $terms, 10 );
			remove_filter( 'epm_delete_data_on_uninstall', '__return_true' );
			if ( false === $rewrite_rules ) {
				delete_option( 'rewrite_rules' );
			} else {
				update_option( 'rewrite_rules', $rewrite_rules );
			}
			EpisodePostType::register_topics();
			foreach ( $options as $option ) {
				delete_option( $option['option_name'] );
				add_option( $option['option_name'], maybe_unserialize( $option['option_value'] ), '', in_array( $option['autoload'], [ 'yes', 'on', 'auto-on' ], true ) );
			}
			wp_cache_flush();
			if ( get_post( $episode ) ) {
				wp_delete_post( $episode, true );
			}
			if ( $term_id > 0 && term_exists( $term_id, EpisodePostType::TOPIC ) ) {
				wp_delete_term( $term_id, EpisodePostType::TOPIC );
			}
		}
	}
);

$t->test(
	'no plugin-triggered _doing_it_wrong notices during the run',
	static function ( EPM_Test_Runner $t ) {
		$t->same( [], $GLOBALS['epm_test_doing_it_wrong'] );
	}
);

/* ------------------------------------------------------------------------- */
/* Put the site back                                                         */
/* ------------------------------------------------------------------------- */

ImportJob::cancel();
ImportJob::release_lock();
EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$offline = false;

$epm_h_created = array_map( 'intval', $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE ID > %d ORDER BY ID DESC", $GLOBALS['epm_h_max_id'] ) ) );
foreach ( $epm_h_created as $epm_h_id ) {
	if ( 'attachment' === get_post_type( $epm_h_id ) ) {
		wp_delete_attachment( $epm_h_id, true );
	} else {
		wp_delete_post( $epm_h_id, true );
	}
}
foreach ( array_keys( $GLOBALS['epm_h_options'] ) as $epm_h_name ) {
	epm_h_restore( $epm_h_name );
}
foreach ( [ Hosting::CRON_HOOK, ImportJob::CRON_HOOK, Feed::PING_HOOK, Feed::PING_FILTER ] as $epm_h_hook ) {
	wp_clear_scheduled_hook( $epm_h_hook );
}
Feed::flush_cache();
WP_CLI::log( sprintf( 'Cleaned up %d posts created by the run.', count( $epm_h_created ) ) );

$t->finish();
