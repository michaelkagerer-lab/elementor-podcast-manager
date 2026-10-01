<?php
/**
 * Integration tests for the feed build, its cache, feed HTTP semantics,
 * the upgrade routine and the readiness report on larger catalogs,
 * executed inside WordPress after the fixtures are seeded
 * (tests/fixtures/seed.php):
 *
 *   wp eval-file tests/integration/feed.php
 *
 * - the feed built a page at a time is byte for byte the feed the 1.3
 *   builder made (tests/integration/reference/Feed-1.3.php), for episodic
 *   and serial shows and several limits; the readiness report agrees with
 *   the 1.3 report (reference/Readiness-1.3.php) on a small catalog;
 * - the feed cache: pieces no larger than FeedWriter::CHUNK, a build
 *   overtaken by a change is not stored, missing pieces are rebuilt;
 * - Last-Modified never in the future, If-None-Match lists and "*",
 *   characters XML does not allow, cache invalidation for media files and
 *   the site title, archive-feed and previous-address routing, a changed
 *   feed address;
 * - GUIDs exist from creation on and are the same for every request,
 *   duplicate GUID rows collapse to the one WordPress returned;
 * - the upgrade stores the version first, queues the per-episode work and
 *   finishes it in batches, under a memory limit far below the catalog;
 * - the delivery test checks the first enclosure of the feed;
 * - listing links must belong to the platform; YouTube's requirements.
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
require_once __DIR__ . '/reference/Feed-1.3.php';
require_once __DIR__ . '/reference/Readiness-1.3.php';
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
foreach ( [ PodcastSettings::OPTION, Hosting::OPTION, Directories::OPTION, Feed::BUILD_OPTION, Feed::ADDRESS_OPTION, 'epm_version', 'blogname', 'permalink_structure', 'powerpress_general' ] as $epm_f_name ) {
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
/* PERF-01 / PERF-N1: the paged build makes the same feed                    */
/* ------------------------------------------------------------------------- */

$t->test(
	'the feed built a page at a time is byte for byte the 1.3 feed (episodic and serial, limits 0, 1, 2, 3 and 500)',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		// More cases than the seed: a trailer (channel-level element), XML
		// special characters, CDATA, imported episodes with external audio
		// and artwork, a measurement prefix, the per-episode feed filter.
		$extra   = [];
		$extra[] = epm_f_episode( 'Trailer: <Coming> & "soon"', '2026-05-01 09:00:00', [ 'episode_type' => 'trailer', 'season_number' => 3, 'show_notes' => '<p>Notes with ]]> inside</p>', 'artwork_url' => 'https://feeds.example.test/media/trailer.png' ] );
		$extra[] = epm_f_episode( 'Imported one', '2026-04-01 09:00:00', [ 'source' => 'import', 'chapters' => [ [ 'time' => '0:00', 'title' => 'Start', 'url' => '' ] ], 'guest_name' => 'Ann' ] );
		$extra[] = epm_f_episode( 'Without distributable audio', '2026-09-03 09:00:00', [ 'audio_url' => 'https://feeds.example.test/media/master.wav', 'audio_type' => 'audio/wav' ] );
		$filter  = static function ( $data ) {
			$data['title'] = $data['title'] . ' (filtered)';
			return $data;
		};
		add_filter( 'epm_feed_episode', $filter );

		$cases = [];
		foreach ( [ 'episodic', 'serial' ] as $type ) {
			foreach ( [ 0, 1, 2, 3, 500 ] as $limit ) {
				$cases[] = [ $type, $limit, '' ];
			}
		}
		$cases[] = [ 'episodic', 500, 'op3' ];
		$cases[] = [ 'serial', 2, 'op3' ];

		$hosting = Hosting::all();
		foreach ( $cases as [ $type, $limit, $stats ] ) {
			epm_f_settings(
				[
					'type'       => $type,
					'feed_limit' => $limit,
				]
			);
			update_option( Hosting::OPTION, Hosting::sanitize( array_merge( $hosting, [ 'stats' => $stats ] ) ) );
			Feed::flush_cache();
			$new = epm()->feed->get_document()['xml'];
			$old = epm_f_reference_xml();
			$t->same( epm_f_strip( $old ), epm_f_strip( $new ), "$type, limit $limit" . ( '' !== $stats ? ", $stats prefix" : '' ) );
			$t->assert( false !== simplexml_load_string( $new ), "$type, limit $limit: well-formed" );
		}
		update_option( Hosting::OPTION, $hosting );
		remove_filter( 'epm_feed_episode', $filter );

		// The trailer and the serial order are really in there.
		epm_f_settings( [ 'type' => 'serial', 'feed_limit' => 2 ] );
		$xp     = epm_test_xpath( epm_test_feed() );
		$titles = [];
		foreach ( $xp->query( '/rss/channel/item/title' ) as $node ) {
			$titles[] = $node->textContent;
		}
		$t->same( [ 'Episode Two', 'Episode Three (bonus)' ], $titles, 'serial window: the newest two, oldest first' );
		epm_f_settings( [ 'type' => 'episodic', 'feed_limit' => 500 ] );
		$t->same( 1, epm_test_xpath( epm_test_feed() )->query( '/rss/channel/podcast:trailer' )->length, 'the trailer is announced on the channel' );

		foreach ( $extra as $id ) {
			wp_delete_post( $id, true );
		}
	}
);

$t->test(
	'episodes with the same publish time keep a fixed order (newest ID first) across pages',
	static function ( EPM_Test_Runner $t ) {
		$ids = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = epm_f_episode( 'Same time ' . $i, '2025-01-01 12:00:00' );
		}
		epm_f_settings( [ 'feed_limit' => 0 ] );
		$xp     = epm_test_xpath( epm_test_feed() );
		$titles = [];
		foreach ( $xp->query( '/rss/channel/item/title' ) as $node ) {
			if ( 0 === strpos( $node->textContent, 'Same time' ) ) {
				$titles[] = $node->textContent;
			}
		}
		$t->same( [ 'Same time 5', 'Same time 4', 'Same time 3', 'Same time 2', 'Same time 1' ], $titles );
		$t->same( array_reverse( $ids ), array_values( array_intersect( epm()->feed->eligible_ids( 0 ), $ids ) ), 'eligible_ids() in the same order' );
		epm_f_settings( [ 'feed_limit' => 500 ] );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
);

$t->test(
	'the feed of 600 episodes needs the memory of a page: limits 20 and 500 build within 8 MB, and so does the unlimited feed',
	static function ( EPM_Test_Runner $t ) {
		epm_perf_catalog( 600, 8, true );
		foreach ( [ 20, 500, 0 ] as $limit ) {
			epm_f_settings( [ 'feed_limit' => $limit ] );
			Feed::flush_cache();
			wp_cache_flush();
			gc_collect_cycles();
			$before = memory_get_usage();
			if ( function_exists( 'memory_reset_peak_usage' ) ) {
				memory_reset_peak_usage();
			}
			$served = epm_f_serve();
			$peak   = ( memory_get_peak_usage() - $before ) / MB_IN_BYTES;
			$items  = substr_count( $served['body'], '<item>' );
			$t->assert( $peak < 8, sprintf( 'limit %d: %.1f MB', $limit, $peak ) );
			$t->same( 0 === $limit ? epm_perf_distributable( 600, true ) + 3 : min( $limit, epm_perf_distributable( 600, true ) + 3 ), $items, "limit $limit: items" );
		}
		epm_f_settings( [ 'feed_limit' => 500 ] );
		epm_perf_catalog_reset();
	}
);

/* ------------------------------------------------------------------------- */
/* The feed cache                                                            */
/* ------------------------------------------------------------------------- */

$t->test(
	'the cached feed is kept in pieces of at most 256 KB, read back intact, and a 304 needs none of them',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		epm_perf_catalog( 800, 0, false, 1200 );
		epm_f_settings( [ 'feed_limit' => 0 ] );
		Feed::flush_cache();
		$document = epm()->feed->get_document();
		$stored   = FeedStore::current();
		$t->assert( null !== $stored && $stored['chunks'] >= 4, 'stored in ' . ( $stored['chunks'] ?? 0 ) . ' pieces' );
		$largest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'epm_feed' ) . '%' ) );
		$t->assert( $largest <= FeedWriter::CHUNK + 64 * KB_IN_BYTES, "largest row $largest bytes" );
		$t->same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%transient%epm_feed_cache%'" ), 'no transient row' );
		$t->same( $stored['etag'], $document['etag'], 'same document' );
		$t->assert( false !== simplexml_load_string( $document['xml'] ), 'well-formed after reassembly' );

		$served = epm_f_serve();
		$t->same( 200, $served['status'], 'served' );
		$t->same( $document['xml'], $served['body'], 'streamed piece by piece, byte for byte' );

		// A conditional request reads the pointer only.
		$reads = 0;
		$count = static function ( $query ) use ( &$reads ) {
			if ( false !== strpos( (string) $query, 'epm_feed_chunk_' ) ) {
				++$reads;
			}
			return $query;
		};
		add_filter( 'query', $count );
		$not_modified = epm_f_serve( [ 'HTTP_IF_NONE_MATCH' => $document['etag'] ] );
		remove_filter( 'query', $count );
		$t->same( 304, $not_modified['status'], '304' );
		$t->same( '', $not_modified['body'], 'no body' );
		$t->same( 0, $reads, 'no piece read for a 304' );

		// Pieces removed under a stored feed: rebuilt, never a broken feed.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", FeedStore::chunk_name( $stored['gen'], 2 ) ) );
		$served = epm_f_serve();
		$t->same( 200, $served['status'], 'served after a piece went missing' );
		$t->assert( false !== simplexml_load_string( $served['body'] ) && substr_count( $served['body'], '<item>' ) === substr_count( $document['xml'], '<item>' ), 'complete after a piece went missing' );

		epm_f_settings( [ 'feed_limit' => 500 ] );
		epm_perf_catalog_reset();
	}
);

$t->test(
	'one request builds at a time; a build that a change overtook is served to its request but not stored; old pieces are removed',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		Feed::flush_cache();
		$claim = FeedStore::claim();
		$t->assert( null !== $claim, 'a request claims the build' );
		$t->same( null, FeedStore::claim(), 'a second request does not' );

		// The show changes while the first request builds.
		Feed::flush_cache();
		$t->same( false, FeedStore::publish( $claim, [ 'chunks' => 1, 'etag' => '"x"', 'modified' => time(), 'bytes' => 1 ] ), 'the overtaken build is not stored' );
		$t->same( null, FeedStore::current(), 'nothing stored' );

		// Builds older than five minutes, beyond the newest two, go away.
		FeedStore::purge();
		$now = time();
		foreach ( [ 3600, 2400, 1800, 1200 ] as $age ) {
			FeedStore::put( ( $now - $age ) . '_old' . $age, 0, 'x' );
		}
		epm()->feed->get_document();
		$left = [];
		foreach ( [ 3600, 2400, 1800, 1200 ] as $age ) {
			if ( null !== \EPM\OptionRow::read( FeedStore::chunk_name( ( $now - $age ) . '_old' . $age, 0 ) ) ) {
				$left[] = $age;
			}
			FeedStore::discard( ( $now - $age ) . '_old' . $age );
		}
		$t->same( [ 1800, 1200 ], $left, 'the two newest older builds stay for requests still sending them' );

		// A claim of a request that died is taken over after two minutes.
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) wp_json_encode( [ 'v' => 2, 'building' => '1_dead', 'since' => time() - 600 ] ) ], [ 'option_name' => FeedStore::POINTER ] );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", FeedStore::POINTER, (string) wp_json_encode( [ 'v' => 2, 'building' => '1_dead', 'since' => time() - 600 ] ) ) );
		$t->assert( null !== FeedStore::claim(), 'an abandoned build is taken over' );
		Feed::flush_cache();
	}
);

/* ------------------------------------------------------------------------- */
/* FEED-N10 / N11 / N12 / N9                                                 */
/* ------------------------------------------------------------------------- */

$t->test(
	'FEED-N10: Last-Modified is never later than now, also after an episode dated in the future, and a stored future time is repaired',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$future = epm_f_episode( 'Dated next year', gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) );
		wp_publish_post( $future );
		Feed::flush_cache();
		$document = epm()->feed->get_document();
		$t->assert( $document['modified'] <= time(), 'build time ' . gmdate( 'c', $document['modified'] ) );
		$t->assert( false !== strpos( $document['xml'], 'Dated next year' ), 'the episode is in the feed' );
		wp_delete_post( $future, true );

		update_option(
			Feed::BUILD_OPTION,
			[
				'hash'     => 'x',
				'modified' => time() + YEAR_IN_SECONDS,
			],
			false
		);
		Feed::repair_build_time();
		$t->assert( (int) get_option( Feed::BUILD_OPTION )['modified'] <= time(), 'repaired by the upgrade' );

		// A channel change answers If-Modified-Since with the new feed.
		Feed::flush_cache();
		$before = epm()->feed->get_document();
		sleep( 1 );
		epm_f_settings( [ 'copyright' => 'Changed ' . wp_generate_password( 6, false ) ] );
		$since  = gmdate( 'D, d M Y H:i:s', $before['modified'] ) . ' GMT';
		$served = epm_f_serve( [ 'HTTP_IF_MODIFIED_SINCE' => $since ] );
		$t->same( 200, $served['status'], 'If-Modified-Since after a change: 200' );
	}
);

$t->test(
	'FEED-N11: If-None-Match matches "*", lists, weak tags and unquoted tags, but never a tag that only contains the ETag',
	static function ( EPM_Test_Runner $t ) {
		$etag = '"2e495b06c576d960e56af04a55e1194b"';
		foreach ( [ '*', $etag, '"abc", ' . $etag, 'W/' . $etag, '"abc",W/' . $etag, trim( $etag, '"' ) ] as $header ) {
			$t->assert( Feed::etag_matches( $header, $etag ), "matches: $header" );
		}
		foreach ( [ '"xx2e495b06c576d960e56af04a55e1194byy"', '"stale"', '"abc", "def"', '' ] as $header ) {
			$t->assert( ! Feed::etag_matches( $header, $etag ), "no match: $header" );
		}

		Feed::flush_cache();
		$document = epm()->feed->get_document();
		$t->same( 304, epm_f_serve( [ 'HTTP_IF_NONE_MATCH' => '*' ] )['status'], '"*" answers 304' );
		$t->same( 200, epm_f_serve( [ 'HTTP_IF_NONE_MATCH' => '"xx' . trim( $document['etag'], '"' ) . 'yy"' ] )['status'], 'a tag containing the ETag answers 200' );
	}
);

$t->test(
	'FEED-N12: U+FFFE, U+FFFF, control characters and invalid UTF-8 in titles, notes and channel text never break the feed',
	static function ( EPM_Test_Runner $t ) {
		// Injected where the feed reads them (a database may refuse invalid
		// UTF-8 on the way in).
		$bad     = "Bad \u{FFFE}chars\u{FFFF} \x01here \xC3\x28 ok ü 😀 & <b>";
		$id      = epm_f_episode( 'Bad characters', '2026-09-05 10:00:00' );
		$episode = static function ( $data ) use ( $id, $bad ) {
			if ( (int) $data['id'] === $id ) {
				$data['title']             = $bad;
				$data['show_notes']        = '<p>' . $bad . '</p>';
				$data['guest_name']        = $bad;
				$data['short_description'] = $bad;
			}
			return $data;
		};
		$channel = static function ( $value ) use ( $bad ) {
			if ( is_array( $value ) ) {
				$value['description'] = 'Channel ' . $bad;
			}
			return $value;
		};
		add_filter( 'epm_episode_data', $episode );
		add_filter( 'option_' . PodcastSettings::OPTION, $channel );
		Episodes::clear_data_cache( $id );
		Feed::flush_cache();
		$xml = epm()->feed->get_document()['xml'];
		libxml_use_internal_errors( true );
		$sx = simplexml_load_string( $xml );
		libxml_clear_errors();
		$t->assert( false !== $sx, 'well-formed' );
		$t->assert( false === strpos( $xml, "\u{FFFE}" ) && false === strpos( $xml, "\u{FFFF}" ) && false === strpos( $xml, "\x01" ), 'noncharacters and control characters removed' );
		$t->assert( false !== strpos( $xml, 'ok ü 😀 &amp;' ), 'the rest kept' );
		$t->assert( false !== strpos( $xml, "\u{FFFD}(" ), 'invalid UTF-8 replaced, the field not emptied' );
		$t->same( 'Bad chars here ' . "\u{FFFD}" . '( ok ü 😀 &amp; &lt;b&gt;', epm_esc_xml( "Bad \u{FFFE}chars\u{FFFF} \x01here \xC3\x28 ok ü 😀 & <b>" ), 'epm_esc_xml()' );

		remove_filter( 'epm_episode_data', $episode );
		remove_filter( 'option_' . PodcastSettings::OPTION, $channel );
		Episodes::clear_data_cache( $id );
		wp_delete_post( $id, true );
	}
);

$t->test(
	'FEED-N9: replacing an episode\'s audio file or its metadata, and renaming the site, update the cached feed',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		Feed::flush_cache();
		epm()->feed->get_document();
		$t->assert( null !== FeedStore::current(), 'cached' );

		$meta             = wp_get_attachment_metadata( $fx['audio_1'] );
		$changed          = $meta;
		$changed['filesize'] = 999999;
		wp_update_attachment_metadata( $fx['audio_1'], $changed );
		$t->assert( false !== strpos( epm()->feed->get_document()['xml'], 'length="999999"' ), 'new length' );
		wp_update_attachment_metadata( $fx['audio_1'], $meta );

		$file = get_attached_file( $fx['audio_1'] );
		$copy = dirname( $file ) . '/epm-episode-1-swapped.mp3';
		copy( $file, $copy );
		update_attached_file( $fx['audio_1'], $copy );
		$t->assert( false !== strpos( epm()->feed->get_document()['xml'], 'epm-episode-1-swapped.mp3' ), 'new file' );
		update_attached_file( $fx['audio_1'], $file );
		wp_delete_file( $copy );

		$settings = epm()->settings->all();
		epm_f_settings( [ 'title' => '' ] );
		epm()->feed->get_document();
		update_option( 'blogname', 'Renamed Blog ' . wp_generate_password( 4, false ) );
		$t->assert( false !== strpos( epm()->feed->get_document()['xml'], '<title>Renamed Blog' ), 'the site title stands in for an empty podcast title' );
		update_option( PodcastSettings::OPTION, $settings );
		epm_f_restore( 'blogname' );
	}
);

/* ------------------------------------------------------------------------- */
/* FEED-N3 / N8 / N15: routing                                               */
/* ------------------------------------------------------------------------- */

$t->test(
	'FEED-N3/N8: archive feeds become the podcast feed before WordPress answers them; the previous address only when turned on',
	static function ( EPM_Test_Runner $t ) {
		$t->same( [ Feed::QUERY_VAR => '1' ], Feed::route_request( [ 'post_type' => EpisodePostType::CPT, 'feed' => 'rss2' ] ), '/podcast/rss2/' );
		$t->same( [ Feed::QUERY_VAR => '1' ], Feed::route_request( [ 'post_type' => EpisodePostType::CPT, 'feed' => 'atom' ] ), '/podcast/feed/atom/' );
		$t->same( [ 'post_type' => EpisodePostType::CPT, 'feed' => 'rss2', 's' => 'x' ], Feed::route_request( [ 'post_type' => EpisodePostType::CPT, 'feed' => 'rss2', 's' => 'x' ] ), 'a search feed stays WordPress\'s' );
		$t->same( [ 'feed' => 'rss2' ], Feed::route_request( [ 'feed' => 'rss2' ] ), 'the blog feed stays WordPress\'s' );

		epm_f_settings( [ 'feed_alias' => false ] );
		$t->same( [ 'feed' => 'podcast' ], Feed::route_request( [ 'feed' => 'podcast' ] ), 'previous address off: untouched (404)' );
		epm_f_settings( [ 'feed_alias' => true ] );
		$t->same( [ Feed::ALIAS_VAR => '1' ], Feed::route_request( [ 'feed' => 'podcast' ] ), 'previous address on: redirected' );
		global $wp_rewrite;
		$t->assert( in_array( 'podcast', (array) $wp_rewrite->feeds, true ), '/feed/podcast/ has a rewrite rule' );
		epm_f_settings( [ 'feed_alias' => false ] );
		$t->assert( ! in_array( 'podcast', (array) $wp_rewrite->feeds, true ), 'and loses it when turned off' );
	}
);

$t->test(
	'FEED-N8: the setup assistant and the settings offer the previous address when PowerPress or Seriously Simple Podcasting left settings',
	static function ( EPM_Test_Runner $t ) {
		delete_option( 'powerpress_general' );
		$t->same( [], Feed::previous_plugins(), 'nothing found' );
		update_option( 'powerpress_general', [ 'title' => 'Old' ], false );
		$t->same( [ 'PowerPress' ], Feed::previous_plugins(), 'PowerPress found' );

		ob_start();
		require EPM_PATH . 'admin/views/setup.php';
		$html = (string) ob_get_clean();
		$t->assert( false !== strpos( $html, 'name="feed_alias"' ) && false !== strpos( $html, 'PowerPress' ), 'the setup assistant offers it' );

		$pages = new AdminPages();
		$pages->save_step( 'show', [ 'title' => 'Test & Talk Podcast', 'feed_alias' => 'true' ] );
		$t->same( true, (bool) epm()->settings->get( 'feed_alias' ), 'and saves it' );
		$pages->save_step( 'show', [ 'title' => 'Test & Talk Podcast', 'feed_alias' => 'false' ] );
		$t->same( false, (bool) epm()->settings->get( 'feed_alias' ), 'or not' );
		epm_f_restore( 'powerpress_general' );
	}
);

$t->test(
	'FEED-N15: a feed address that changed after it was shown for submission is reported until the new one is confirmed',
	static function ( EPM_Test_Runner $t ) {
		global $wp_rewrite;

		delete_option( Feed::ADDRESS_OPTION );
		wp_set_current_user( (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 ) );
		ob_start();
		$pages = new AdminPages();
		$pages->render_distribution();
		ob_end_clean();
		wp_set_current_user( 0 );
		$t->same( Feed::url(), get_option( Feed::ADDRESS_OPTION ), 'the address shown on Distribution is remembered' );
		$t->same( null, Feed::address_change(), 'unchanged' );

		$wp_rewrite->set_permalink_structure( '' );
		$change = Feed::address_change();
		$t->assert( null !== $change && false !== strpos( $change['now'], Feed::QUERY_VAR ), 'plain permalinks: the address changed' );
		$found = null;
		foreach ( Readiness::report()['checks'] as $check ) {
			if ( 'Feed address' === $check['label'] ) {
				$found = $check;
			}
		}
		$t->assert( null !== $found && 'error' === $found['status'] && false !== strpos( $found['message'], '/podcast/feed/' ), 'the readiness report names both addresses' );
		$t->same( [ Feed::QUERY_VAR => '1' ], ( static function () {
			$_SERVER['REQUEST_URI'] = wp_parse_url( home_url( '/podcast/feed/' ), PHP_URL_PATH );
			return Feed::route_request( [] );
		} )(), 'the old pretty address still serves the feed under plain permalinks' );

		unset( $_SERVER['REQUEST_URI'] );

		Feed::accept_address();
		$t->same( null, Feed::address_change(), 'confirmed' );

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		epm_f_restore( Feed::ADDRESS_OPTION );
		epm_f_restore( 'permalink_structure' );
	}
);

/* ------------------------------------------------------------------------- */
/* FEED-N6: GUIDs                                                            */
/* ------------------------------------------------------------------------- */

$t->test(
	'FEED-N6: every new episode has its GUID right after it is created, without the editor, and it is the same for every request',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		unset( $_POST['epm_episode_meta_nonce'] );
		$id   = (int) wp_insert_post( [ 'post_type' => EpisodePostType::CPT, 'post_status' => 'publish', 'post_title' => 'Made by an integration' ] );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_epm_guid'", $id ) );
		$t->same( 1, count( $rows ), 'one GUID row right after wp_insert_post()' );
		$t->same( Episodes::new_guid( $id ), $rows[0] ?? '', 'derived from the episode' );
		$t->assert( (bool) preg_match( '/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) ( $rows[0] ?? '' ) ), 'a URN UUID' );

		// An episode written without hooks: every request derives the same.
		$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => '_epm_guid' ] );
		wp_cache_delete( $id, 'post_meta' );
		$first = Episodes::new_guid( $id );
		$t->same( $first, Episodes::get_guid( $id ), 'first read' );
		$t->same( $first, Episodes::new_guid( $id ), 'any other request computes the same' );

		// An imported episode keeps its host's GUID.
		$import = (int) wp_insert_post( [ 'post_type' => EpisodePostType::CPT, 'post_status' => 'publish', 'post_title' => 'Imported', 'meta_input' => [ '_epm_guid' => 'host-guid-1' ] ] );
		$t->same( [ 'host-guid-1' ], $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_epm_guid'", $import ) ), 'the host\'s GUID, alone' );

		wp_delete_post( $id, true );
		wp_delete_post( $import, true );
	}
);

$t->test(
	'FEED-N6: the upgrade collapses duplicate GUID rows to the one WordPress returned (the oldest row)',
	static function ( EPM_Test_Runner $t ) {
		global $wpdb;

		$id = (int) wp_insert_post( [ 'post_type' => EpisodePostType::CPT, 'post_status' => 'publish', 'post_title' => 'Raced' ] );
		$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => '_epm_guid' ] );
		// Two requests stored a random GUID each (1.3).
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => '_epm_guid', 'meta_value' => 'urn:uuid:first-served' ] );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => '_epm_guid', 'meta_value' => 'urn:uuid:second' ] );
		wp_cache_delete( $id, 'post_meta' );
		$served = Episodes::get_guid( $id );
		$t->same( 'urn:uuid:first-served', $served, 'WordPress returns the oldest row' );

		$record = get_option( Episodes::REMOVED_GUIDS_OPTION, '__absent__' );
		$t->same( 1, Episodes::collapse_guid_rows( 100 ), 'one row removed' );
		$removed = (array) get_option( Episodes::REMOVED_GUIDS_OPTION, [] );
		$last    = end( $removed );
		$t->assert( is_array( $last ) && $id === $last['post_id'] && 'urn:uuid:second' === $last['guid'] && 'urn:uuid:first-served' === $last['kept'], 'the removed value is recorded for recovery' );
		'__absent__' === $record ? delete_option( Episodes::REMOVED_GUIDS_OPTION ) : update_option( Episodes::REMOVED_GUIDS_OPTION, $record, false );
		$t->same( [ 'urn:uuid:first-served' ], $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_epm_guid'", $id ) ), 'the served GUID stays' );
		$t->same( 0, Episodes::collapse_guid_rows( 100 ), 'nothing left' );
		wp_delete_post( $id, true );
	}
);

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
/* PERF-N4: readiness                                                        */
/* ------------------------------------------------------------------------- */

$t->test(
	'PERF-N4: the readiness report worked through page by page equals the 1.3 report on a small catalog with problems',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$extra   = [];
		$extra[] = epm_f_episode( 'Plain http audio', '2026-03-01 10:00:00', [ 'audio_url' => 'http://feeds.example.test/media/plain.mp3' ] );
		$extra[] = epm_f_episode( 'Same audio as another', '2026-03-02 10:00:00', [ 'audio_url' => 'http://feeds.example.test/media/plain.mp3' ] );
		$extra[] = epm_f_episode( 'Unknown size', '2026-03-03 10:00:00', [ 'audio_length' => 0 ] );
		$extra[] = epm_f_episode( 'Deleted file', '2026-03-04 10:00:00', [ 'audio_url' => '', 'audio_id' => 999999 ] );
		$extra[] = epm_f_episode( 'Imported, left at the host', '2026-03-05 10:00:00', [ 'source' => 'import' ] );

		foreach ( [ false, true ] as $moved_in ) {
			epm_f_settings( [ 'moved_in' => $moved_in ] );
			$new = Readiness::report();
			$old = \EPM\Reference\Readiness::report();
			// 1.4 adds the episodes behind a check (items, more) to every check.
			foreach ( $new['checks'] as &$check ) {
				unset( $check['items'], $check['more'] );
			}
			unset( $check );
			$t->same( $old, $new, $moved_in ? 'moved in' : 'self-hosted' );
		}
		epm_f_settings( [ 'moved_in' => false ] );
		foreach ( $extra as $id ) {
			wp_delete_post( $id, true );
		}
	}
);

$t->test(
	'PERF-N4: with many problem episodes the first 50 are listed and the rest counted, with the same totals',
	static function ( EPM_Test_Runner $t ) {
		epm_perf_catalog( 120, 0, false );
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, 'https://', 'http://') WHERE meta_key = '_epm_audio_url' AND meta_value LIKE 'https://feeds.example.test/media/perf-cat-%'" );
		wp_cache_flush();

		$old = \EPM\Reference\Readiness::report();
		$new = Readiness::report();
		$t->same( $old['errors'], $new['errors'], 'errors' );
		$t->same( $old['warnings'], $new['warnings'], 'warnings' );
		$episode_checks = array_filter(
			$new['checks'],
			static function ( $check ) {
				return 0 === strpos( $check['label'], 'Episode: ' );
			}
		);
		$t->same( 50, count( $episode_checks ), 'fifty listed' );
		$more = array_values(
			array_filter(
				$new['checks'],
				static function ( $check ) {
					return 'More episodes' === $check['label'];
				}
			)
		);
		$all = count(
			array_filter(
				$old['checks'],
				static function ( $check ) {
					return 0 === strpos( $check['label'], 'Episode: ' );
				}
			)
		);
		$t->assert( 1 === count( $more ) && false !== strpos( $more[0]['message'], number_format_i18n( $all - 50 ) . ' more' ), 'the rest counted: ' . ( $more[0]['message'] ?? '' ) );
		epm_perf_catalog_reset();
	}
);

/* ------------------------------------------------------------------------- */
/* FEED-N4: the delivery test                                                */
/* ------------------------------------------------------------------------- */

$t->test(
	'FEED-N4: the delivery test checks the first enclosure of the feed (not the newest audio), with the measurement prefix',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$label = static function ( array $checks, string $label ): ?array {
			foreach ( $checks as $check ) {
				if ( $label === $check['label'] ) {
					return $check;
				}
			}
			return null;
		};

		// The newest episode with audio is "WAV only", which is not in the feed.
		$checks = AdminPages::server_check();
		$tested = $label( $checks, 'Episode tested' );
		$t->assert( null !== $tested && false !== strpos( $tested['message'], 'epm-episode-3.mp3' ), 'tests epm-episode-3.mp3: ' . ( $tested['message'] ?? '' ) );
		$t->same( 'ok', $label( $checks, 'Audio (byte ranges)' )['status'] ?? '', 'ranges ok' );

		// A broken statistics prefix: every enclosure answers 404.
		$hosting = Hosting::all();
		update_option( Hosting::OPTION, Hosting::sanitize( array_merge( $hosting, [ 'stats' => 'custom', 'stats_prefix' => 'https://typo-stats.example.test/e/' ] ) ) );
		$checks = AdminPages::server_check();
		$t->assert( false !== strpos( (string) ( $label( $checks, 'Episode tested' )['message'] ?? '' ), 'typo-stats.example.test' ), 'tests the prefixed address' );
		$t->same( 'error', $label( $checks, 'Audio (HEAD)' )['status'] ?? '', 'HEAD 404 is an error' );

		// A prefix that redirects to the file: followed.
		update_option( Hosting::OPTION, Hosting::sanitize( array_merge( $hosting, [ 'stats' => 'custom', 'stats_prefix' => 'https://redirect-stats.example.test/e/' ] ) ) );
		$item = epm()->feed->first_item();
		EPM_Test_HTTP::$routes[ $item['url'] ] = static function ( $args ) use ( $item ) {
			if ( empty( $args['redirection'] ) ) {
				return EPM_Test_HTTP::response( 302, '', [ 'location' => 'https://feeds.example.test/media/final.mp3' ] );
			}
			$range = (string) ( $args['headers']['Range'] ?? '' );
			return '' !== $range
				? EPM_Test_HTTP::response( 206, 'ab', [ 'content-type' => 'audio/mpeg', 'content-range' => 'bytes 0-1/' . $item['length'], 'content-length' => '2' ] )
				: EPM_Test_HTTP::response( 200, '', [ 'content-type' => 'audio/mpeg', 'content-length' => (string) $item['length'] ] );
		};
		$checks = AdminPages::server_check();
		$t->same( 'ok', $label( $checks, 'Audio (HEAD)' )['status'] ?? '', 'redirect followed for HEAD' );
		$t->same( 'ok', $label( $checks, 'Audio (byte ranges)' )['status'] ?? '', 'and for the range request' );

		// A server that answers ranges with a wrong Content-Range.
		EPM_Test_HTTP::$routes[ $item['url'] ] = static function ( $args ) use ( $item ) {
			$range = (string) ( $args['headers']['Range'] ?? '' );
			return '' !== $range
				? EPM_Test_HTTP::response( 206, str_repeat( 'a', 100 ), [ 'content-type' => 'audio/mpeg', 'content-range' => 'bytes 0-99/' . $item['length'], 'content-length' => '100' ] )
				: EPM_Test_HTTP::response( 200, '', [ 'content-type' => 'audio/mpeg', 'content-length' => (string) ( $item['length'] + 5 ) ] );
		};
		$checks = AdminPages::server_check();
		$t->same( 'warning', $label( $checks, 'Audio (HEAD)' )['status'] ?? '', 'a size other than the feed\'s is a warning' );
		$t->same( 'error', $label( $checks, 'Audio (byte ranges)' )['status'] ?? '', 'a wrong range is an error' );

		EPM_Test_HTTP::$routes = [];
		update_option( Hosting::OPTION, $hosting );
	}
);

/* ------------------------------------------------------------------------- */
/* FEED-N13 / N14: distribution                                              */
/* ------------------------------------------------------------------------- */

$t->test(
	'FEED-N13: a listing link must be a public link on that platform; dashboards and other platforms are refused and nothing is saved',
	static function ( EPM_Test_Runner $t ) {
		$progress = Directories::progress();
		$settings = epm()->settings->all();

		$result = Directories::save_progress( 'spotify', '', 'https://creators.spotify.com/pod/dashboard/home' );
		$t->assert( is_wp_error( $result ) && 'epm_listing_dashboard' === $result->get_error_code(), 'Spotify dashboard refused' );
		$result = Directories::save_progress( 'apple', '', 'https://open.spotify.com/show/abc' );
		$t->assert( is_wp_error( $result ) && 'epm_listing_platform' === $result->get_error_code(), 'a Spotify link for Apple refused' );
		$t->same( $progress, Directories::progress(), 'progress unchanged' );
		$t->same( $settings['platform_links'], epm()->settings->get( 'platform_links' ), 'no subscribe button added' );

		$t->same( [ 'status' => 'listed', 'url' => 'https://music.youtube.com/playlist?list=abc' ], Directories::save_progress( 'youtube', '', 'https://music.youtube.com/playlist?list=abc' ), 'YouTube Music for YouTube' );
		$t->same( [ 'status' => 'listed', 'url' => 'https://www.audible.com/podcast/abc' ], Directories::save_progress( 'amazon', '', 'https://www.audible.com/podcast/abc' ), 'Audible for Amazon Music & Audible' );
		$t->same( [ 'status' => 'listed', 'url' => 'https://www.podcast.de/podcast/1/' ], Directories::save_progress( 'podcastde', '', 'https://www.podcast.de/podcast/1/' ), 'podcast.de (no known address)' );

		update_option( Directories::OPTION, $progress, false );
		update_option( PodcastSettings::OPTION, $settings );
	}
);

$t->test(
	'FEED-N14: YouTube\'s requirements as Google states them; titles with "<" or ">" are reported while YouTube is tracked',
	static function ( EPM_Test_Runner $t ) {
		$youtube = Directories::get( 'youtube' );
		$t->assert( false !== stripos( $youtube['needs'], 'must not contain advertisements' ), 'no advertisements of any kind' );
		$t->assert( false !== strpos( $youtube['needs'], '“<”' ) && false !== strpos( $youtube['needs'], '“>”' ), '"<" and ">" named' );
		$t->assert( '' !== $youtube['region'], 'available in select countries and regions' );
		$t->same( 'recommended', $youtube['priority'], 'not essential for every show' );

		$id       = epm_f_episode( 'Q&A: 1 < 2 > 0', '2026-03-10 10:00:00' );
		$progress = Directories::progress();
		$warned   = static function (): bool {
			foreach ( Readiness::report()['checks'] as $check ) {
				if ( 'Episode: Q&#038;A: 1 &lt; 2 &gt; 0' === $check['label'] || false !== strpos( $check['message'], 'YouTube does not accept' ) ) {
					return true;
				}
			}
			return false;
		};
		$t->same( false, $warned(), 'no warning while YouTube is not tracked' );
		update_option( Directories::OPTION, array_merge( $progress, [ 'youtube' => [ 'status' => 'submitted', 'url' => '' ] ] ), false );
		$t->same( true, $warned(), 'a warning once it is' );
		update_option( Directories::OPTION, $progress, false );
		wp_delete_post( $id, true );
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
