<?php
/**
 * Integration tests for copying media when a show moves here, executed
 * inside WordPress after the fixtures are seeded (tests/fixtures/seed.php):
 *
 *   wp eval-file tests/integration/media.php
 *
 * Covers what in-process answers can show: a move after a mirror completes
 * every kind of file (audio, episode image, WebVTT/SRT transcript file) and
 * keeps local decisions; what stays at the old host is listed per kind (job,
 * Readiness, WP-CLI); a move with files left behind is not finished
 * silently; download limits, rate limits, HTTP errors, truncated and wrong
 * content; transcripts and chapters fetched before the copy; no duplicate
 * files; WP-CLI cancel and status; a request that dies during a copy
 * (a separate process, see _media-child.php).
 *
 * Downloads over real sockets (streaming limits, a stalled or slow source,
 * resuming, memory) are in tests/media/.
 *
 * Answers come from the fixture HTTP server
 * (tests/fixtures/mu-plugins/epm-test-http.php) and EPM_Test_HTTP::$routes;
 * every other outgoing request is refused. Episodes created here use GUIDs
 * starting with "med-" and are deleted again; the options changed here are
 * restored.
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
use EPM\Readiness;

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

// The import screens are used by an administrator (episode links need one).
wp_set_current_user( (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 ) );

$GLOBALS['epm_m_options'] = [];
foreach ( [ PodcastSettings::OPTION, Hosting::OPTION, Hosting::STATE_OPTION, ImportJob::OPTION, 'epm_import_lock', Feed::GUID_OPTION, Feed::BUILD_OPTION ] as $epm_m_name ) {
	$GLOBALS['epm_m_options'][ $epm_m_name ] = get_option( $epm_m_name, '__epm_absent__' );
}

// URL => HTTP status (or a callable answer) instead of the file.
$GLOBALS['epm_m_fail'] = [];

/* ------------------------------------------------------------------------- */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------- */

/**
 * WebVTT of a speaker.
 *
 * @param string $who Speaker.
 * @return string
 */
function epm_m_vtt( string $who ): string {
	return "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\n<v {$who}>Hello from the old host, {$who}.\n\n00:00:02.000 --> 00:00:04.000\n<v {$who}>Second cue.\n";
}

/**
 * SRT of a speaker.
 *
 * @param string $who Speaker.
 * @return string
 */
function epm_m_srt( string $who ): string {
	return "1\n00:00:00,000 --> 00:00:02,000\n{$who}: Hello from the old host.\n\n2\n00:00:02,000 --> 00:00:04,000\n{$who}: Second cue.\n";
}

/**
 * Answer for the made-up old host: files by extension, or the failure set
 * in $GLOBALS['epm_m_fail'].
 *
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return array|WP_Error
 */
function epm_m_answer( array $args, string $url ) {
	if ( isset( $GLOBALS['epm_m_fail'][ $url ] ) ) {
		$fail = $GLOBALS['epm_m_fail'][ $url ];
		return is_callable( $fail ) ? $fail( $args, $url ) : EPM_Test_HTTP::response( (int) $fail, '<html><body>nope</body></html>', [ 'content-type' => 'text/html' ] );
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$who  = strtoupper( pathinfo( $path, PATHINFO_FILENAME ) );
	switch ( $ext ) {
		case 'mp3':
			$body = EPM_Test_HTTP::mp3( 5 );
			return EPM_Test_HTTP::response( 200, $body, [ 'content-type' => 'audio/mpeg', 'content-length' => (string) strlen( $body ) ] );
		case 'png':
			return EPM_Test_HTTP::response( 200, EPM_Test_HTTP::png( 1400, 1400 ), [ 'content-type' => 'image/png' ] );
		case 'vtt':
			return EPM_Test_HTTP::response( 200, epm_m_vtt( $who ), [ 'content-type' => 'text/vtt' ] );
		case 'srt':
			return EPM_Test_HTTP::response( 200, epm_m_srt( $who ), [ 'content-type' => 'application/x-subrip' ] );
		case 'json':
			return EPM_Test_HTTP::response( 200, (string) wp_json_encode( [ 'version' => '1.0.0', 'segments' => [ [ 'speaker' => $who, 'startTime' => 0, 'endTime' => 2, 'body' => 'JSON transcript of ' . $who ] ] ] ), [ 'content-type' => 'application/json' ] );
		case 'html':
			return EPM_Test_HTTP::response( 200, '<html><body><p>HTML transcript of ' . $who . '</p></body></html>', [ 'content-type' => 'text/html' ] );
		case 'json-chapters':
			return EPM_Test_HTTP::response( 200, (string) wp_json_encode( [ 'version' => '1.2.0', 'chapters' => [ [ 'startTime' => 0, 'title' => 'Start of ' . $who ], [ 'startTime' => 2, 'title' => 'End' ] ] ] ), [ 'content-type' => 'application/json' ] );
	}

	return EPM_Test_HTTP::response( 404, 'Not found' );
}

/**
 * A made-up show at https://oldhost.example.test/<prefix>/ with six
 * episodes, served through EPM_Test_HTTP::$routes:
 *
 * A audio, image, WebVTT and HTML transcripts · B SRT · C JSON transcript ·
 * D image and WebVTT (the site picks another transcript later) · E image
 * and WebVTT · F image and SRT.
 *
 * @param string $prefix GUID prefix and path.
 * @param string $suffix Appended to every title (a change at the host).
 * @return string Feed URL.
 */
function epm_m_show( string $prefix, string $suffix = '' ): string {
	$h     = 'https://oldhost.example.test/' . $prefix;
	$items = [
		'A' => [ "{$h}/img/a.png", [ [ "{$h}/tr/a.vtt", 'text/vtt' ], [ "{$h}/tr/a.html", 'text/html' ] ] ],
		'B' => [ '', [ [ "{$h}/tr/b.srt", 'application/x-subrip' ] ] ],
		'C' => [ '', [ [ "{$h}/tr/c.json", 'application/json' ] ] ],
		'D' => [ "{$h}/img/d.png", [ [ "{$h}/tr/d.vtt", 'text/vtt' ] ] ],
		'E' => [ "{$h}/img/e.png", [ [ "{$h}/tr/e.vtt", 'text/vtt' ] ] ],
		'F' => [ "{$h}/img/f.png", [ [ "{$h}/tr/f.srt", 'application/x-subrip' ] ] ],
	];
	$xml   = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0"><channel>'
		. '<title>Old Host Show</title><link>https://oldhost.example.test/</link><description>Media test show</description><language>en</language>'
		. '<itunes:author>Old</itunes:author><itunes:owner><itunes:name>Old</itunes:name><itunes:email>owner@example.test</itunes:email></itunes:owner>';
	$day   = 1;
	foreach ( $items as $key => [ $image, $transcripts ] ) {
		$audio = "{$h}/audio/" . strtolower( $key ) . '.mp3';
		$xml  .= '<item><title>' . strtoupper( $prefix ) . ' ' . $key . $suffix . '</title><guid isPermaLink="false">med-' . $prefix . '-' . $key . '</guid>'
			. '<pubDate>' . gmdate( DATE_RSS, gmmktime( 8, 0, 0, 5, $day++, 2026 ) ) . '</pubDate>'
			. '<description>Notes ' . $key . '</description>'
			. '<enclosure url="' . $audio . '" length="4300" type="audio/mpeg"/>'
			. ( '' !== $image ? '<itunes:image href="' . $image . '"/>' : '' );
		EPM_Test_HTTP::$routes[ $audio ] = 'epm_m_answer';
		if ( '' !== $image ) {
			EPM_Test_HTTP::$routes[ $image ] = 'epm_m_answer';
		}
		foreach ( $transcripts as [ $url, $type ] ) {
			$xml                           .= '<podcast:transcript url="' . $url . '" type="' . $type . '"/>';
			EPM_Test_HTTP::$routes[ $url ] = 'epm_m_answer';
		}
		$xml .= '</item>';
	}
	$xml .= '</channel></rss>';

	$feed                           = "{$h}/feed.xml";
	EPM_Test_HTTP::$routes[ $feed ] = static function () use ( $xml ) {
		return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
	};

	return $feed;
}

/**
 * Check, start and step an import until it stops (or waits).
 *
 * @param string $url     Feed URL.
 * @param array  $options Options for ImportJob::start().
 * @return array{preview: array, state: array}|WP_Error
 */
function epm_m_import( string $url, array $options ) {
	$preview = ImportJob::preview( $url );
	if ( is_wp_error( $preview ) ) {
		return $preview;
	}
	$state = ImportJob::start( (string) $preview['token'], $options );
	if ( is_wp_error( $state ) ) {
		return $state;
	}

	return [
		'preview' => $preview,
		'state'   => epm_m_steps( $state ),
	];
}

/**
 * Step a running import until it stops (or waits).
 *
 * @param array $state Client state.
 * @return array Final client state.
 */
function epm_m_steps( array $state ): array {
	for ( $i = 0; $i < 300 && 'running' === ( $state['status'] ?? '' ); $i++ ) {
		$state = ImportJob::step( 8.0 );
	}

	return $state;
}

/**
 * Episode with a GUID (0 when none).
 *
 * @param string $guid GUID.
 * @return int
 */
function epm_m_id( string $guid ): int {
	return Importer::find_guid( $guid );
}

/**
 * What an episode holds, per kind of file.
 *
 * @param string $guid GUID.
 * @return array<string, mixed>
 */
function epm_m_row( string $guid ): array {
	$id   = epm_m_id( $guid );
	$meta = static function ( string $key ) use ( $id ) {
		return get_post_meta( $id, '_epm_' . $key, true );
	};
	$file = (int) $meta( 'transcript_file_id' );

	return [
		'audio_id'        => (int) $meta( 'audio_id' ),
		'audio_url'       => (string) $meta( 'audio_url' ),
		'artwork_id'      => (int) $meta( 'artwork_id' ),
		'artwork_url'     => (string) $meta( 'artwork_url' ),
		'transcript_file' => $file > 0 ? (string) get_post_meta( $file, '_epm_source_url', true ) : '',
		'transcript_url'  => (string) $meta( 'transcript_url' ),
		'transcript'      => wp_strip_all_tags( (string) $meta( 'transcript' ) ),
	];
}

/**
 * Old-host URLs requested since the log was cleared (each once, in order).
 *
 * @param string $prefix URL prefix.
 * @return string[]
 */
function epm_m_requested( string $prefix ): array {
	$out = [];
	foreach ( EPM_Test_HTTP::$log as $entry ) {
		if ( 0 === strpos( $entry['url'], $prefix ) && ! in_array( $entry['url'], $out, true ) ) {
			$out[] = $entry['url'];
		}
	}
	sort( $out );

	return $out;
}

/**
 * How often a URL was requested since the log was cleared.
 *
 * @param string $url URL.
 * @return int
 */
function epm_m_count( string $url ): int {
	return count(
		array_filter(
			EPM_Test_HTTP::$log,
			static function ( $entry ) use ( $url ) {
				return $entry['url'] === $url;
			}
		)
	);
}

/**
 * Titles listed as still at the old host, per kind, in a client state.
 *
 * @param array $state Client state.
 * @return array<string, string[]>
 */
function epm_m_remaining( array $state ): array {
	$out = [];
	foreach ( (array) ( $state['remaining'] ?? [] ) as $kind => $group ) {
		$titles = array_column( (array) ( $group['episodes'] ?? [] ), 'title' );
		sort( $titles );
		if ( ! empty( $titles ) ) {
			$out[ $kind ] = $titles;
		}
	}
	ksort( $out );

	return $out;
}

/**
 * The reason listed for an episode and kind in a client state.
 *
 * @param array  $state Client state.
 * @param string $kind  Kind.
 * @param string $title Episode title.
 * @return string
 */
function epm_m_reason( array $state, string $kind, string $title ): string {
	foreach ( (array) ( $state['remaining'][ $kind ]['episodes'] ?? [] ) as $episode ) {
		if ( ( $episode['title'] ?? '' ) === $title ) {
			return (string) ( $episode['reason'] ?? '' );
		}
	}

	return '';
}

/**
 * Readiness checks whose label matches, with the episode titles they list.
 *
 * @param string $pattern Regular expression for the label.
 * @return array<string, array{status: string, message: string, items: string[]}>
 */
function epm_m_readiness( string $pattern ): array {
	$out = [];
	foreach ( Readiness::report()['checks'] as $check ) {
		if ( preg_match( $pattern, (string) $check['label'] ) ) {
			$items = array_column( (array) ( $check['items'] ?? [] ), 'title' );
			sort( $items );
			$out[ (string) $check['label'] ] = [
				'status'  => (string) $check['status'],
				'message' => (string) $check['message'],
				'items'   => $items,
			];
		}
	}
	ksort( $out );

	return $out;
}

/**
 * Old-host URLs in the rendered feed.
 *
 * @param string $prefix URL prefix.
 * @return string[]
 */
function epm_m_feed_urls( string $prefix ): array {
	Feed::flush_cache();
	preg_match_all( '#' . preg_quote( $prefix, '#' ) . '[^"<\s]+#', (string) epm()->feed->get_document()['xml'], $m );
	$urls = array_values( array_unique( $m[0] ) );
	sort( $urls );

	return $urls;
}

/**
 * Delete the episodes of a GUID prefix and every file copied for them.
 *
 * @param string $prefix GUID prefix.
 * @return void
 */
function epm_m_delete( string $prefix ): void {
	global $wpdb;

	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epm_guid' AND meta_value LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $ids as $id ) {
		$files = get_children(
			[
				'post_parent' => (int) $id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			]
		);
		foreach ( [ 'audio_id', 'artwork_id', 'transcript_file_id' ] as $key ) {
			$files[] = (int) get_post_meta( (int) $id, '_epm_' . $key, true );
		}
		foreach ( array_unique( array_filter( array_map( 'intval', $files ) ) ) as $attachment ) {
			wp_delete_attachment( $attachment, true );
		}
		wp_delete_post( (int) $id, true );
	}
}

/**
 * Plugin download files in the temp folder.
 *
 * @return string[]
 */
function epm_m_temp_files(): array {
	return array_map( 'basename', glob( get_temp_dir() . 'epm-media-*' ) ?: [] );
}

/**
 * Files below the uploads folder whose name starts with a string (any
 * year/month folder).
 *
 * @param string $start Name start.
 * @return string[] Paths relative to the uploads folder.
 */
function epm_m_uploads_named( string $start ): array {
	$base  = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
	$found = [];
	if ( ! is_dir( $base ) ) {
		return $found;
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( $file->isFile() && 0 === strpos( $file->getFilename(), $start ) ) {
			$found[] = substr( wp_normalize_path( $file->getPathname() ), strlen( $base ) + 1 );
		}
	}
	sort( $found );

	return $found;
}

/**
 * Reset the import, the hosting mode and the move flags.
 *
 * @param array $hosting Hosting settings.
 * @return void
 */
function epm_m_reset( array $hosting = [ 'mode' => 'self' ] ): void {
	ImportJob::cancel();
	global $wpdb;
	$wpdb->delete( $wpdb->options, [ 'option_name' => 'epm_import_lock' ] );
	wp_cache_delete( 'epm_import_lock', 'options' );
	EPM_Test_HTTP::$log    = [];
	$GLOBALS['epm_m_fail'] = [];
	$settings              = epm()->settings->all();
	$settings['moved_in']  = false;
	$settings['locked']    = false;
	update_option( PodcastSettings::OPTION, epm()->settings->sanitize( $settings ) );
	update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), $hosting ) ) );
	wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
	Feed::flush_cache();
}

/**
 * Move flags and hosting mode.
 *
 * @return array{0: bool, 1: bool, 2: string}
 */
function epm_m_moved(): array {
	return [ (bool) epm()->settings->get( 'moved_in' ), (bool) epm()->settings->get( 'locked' ), (string) Hosting::get( 'mode' ) ];
}

/**
 * Put an option back as it was before the run.
 *
 * @param string $name Option.
 * @return void
 */
function epm_m_restore( string $name ): void {
	$value = $GLOBALS['epm_m_options'][ $name ] ?? '__epm_absent__';
	if ( '__epm_absent__' === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

/**
 * Change the stored job directly (as time passing would).
 *
 * @param callable $change Receives and returns the job.
 * @return void
 */
function epm_m_edit_job( callable $change ): void {
	global $wpdb;
	$job = $change( ImportJob::get() );
	$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $job ) ], [ 'option_name' => ImportJob::OPTION ] );
	wp_cache_delete( ImportJob::OPTION, 'options' );
}

/**
 * A one-episode feed whose audio is served by $answer.
 *
 * @param string   $name   Case name (GUID med-<name>-1).
 * @param callable $answer Answer for the audio URL.
 * @param string   $ext    Audio URL extension ('' for none).
 * @param int      $length Enclosure length.
 * @return array{0: string, 1: string} Feed URL, audio URL.
 */
function epm_m_single( string $name, callable $answer, string $ext = 'mp3', int $length = 4300 ): array {
	$feed  = 'https://oldhost.example.test/single/' . $name . '/feed.xml';
	$audio = 'https://oldhost.example.test/single/' . $name . '/audio' . ( '' !== $ext ? '.' . $ext : '' );
	$xml   = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><title>Single ' . $name . '</title><link>https://oldhost.example.test/</link><description>One episode</description>'
		. '<item><title>Single ' . $name . '</title><guid isPermaLink="false">med-' . $name . '-1</guid><pubDate>' . gmdate( DATE_RSS, gmmktime( 8, 0, 0, 4, 1, 2026 ) ) . '</pubDate>'
		. '<enclosure url="' . $audio . '" length="' . $length . '" type="audio/mpeg"/></item></channel></rss>';
	EPM_Test_HTTP::$routes[ $feed ]  = static function () use ( $xml ) {
		return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
	};
	EPM_Test_HTTP::$routes[ $audio ] = $answer;

	return [ $feed, $audio ];
}

$t = new EPM_Test_Runner();

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'IMP-03: moving a show that was mirrored before' );
/* ------------------------------------------------------------------------- */

foreach ( [ 'unchanged at the host' => '', 'changed at the host' => ' (edited at the host)' ] as $epm_m_case => $epm_m_suffix ) {
	$t->test(
		'mirror, then move (' . $epm_m_case . '): audio, images and WebVTT/SRT files are copied per kind; JSON stays linked; local choices are kept; what failed is listed and retried alone',
		static function ( EPM_Test_Runner $t ) use ( $epm_m_suffix ) {
			$p    = '' === $epm_m_suffix ? 'm3a' : 'm3b';
			$h    = 'https://oldhost.example.test/' . $p;
			$name = strtoupper( $p );
			epm_m_reset();
			$feed = epm_m_show( $p );

			try {
				// 1. Mirror: everything stays at the old host.
				$run = epm_m_import( $feed, [ 'purpose' => 'mirror' ] );
				$t->same( [ 'done', 6 ], [ $run['state']['status'] ?? '', (int) ( $run['state']['counts']['created'] ?? 0 ) ], 'mirrored' );
				$t->same( "{$h}/tr/a.vtt", epm_m_row( "med-{$p}-A" )['transcript_url'], 'the WebVTT file is linked at the old host' );

				// 2. Local decisions on this site.
				$d = epm_m_id( "med-{$p}-D" );
				update_post_meta( $d, '_epm_transcript_url', 'https://captions.example.test/deliberate-d.vtt' );
				update_post_meta( $d, '_epm_transcript_type', 'text/vtt' );
				update_post_meta( $d, '_epm_transcript', '<p>MANUAL EDIT of D</p>' );
				update_post_meta( epm_m_id( "med-{$p}-A" ), '_epm_transcript', '<p>MANUAL EDIT of A</p>' );

				// 3. Move with "Copy audio" while three files fail.
				if ( '' !== $epm_m_suffix ) {
					$feed = epm_m_show( $p, $epm_m_suffix );
				}
				$GLOBALS['epm_m_fail'] = [
					"{$h}/img/e.png"   => 404,
					"{$h}/tr/e.vtt"    => 404,
					"{$h}/audio/f.mp3" => 403,
				];
				EPM_Test_HTTP::$log    = [];
				$run   = epm_m_import(
					$feed,
					[
						'purpose'        => 'move',
						'download_media' => true,
						'confirm_owner'  => true,
					]
				);
				$state = $run['state'];

				$t->same(
					[
						"{$h}/audio/a.mp3",
						"{$h}/audio/b.mp3",
						"{$h}/audio/c.mp3",
						"{$h}/audio/d.mp3",
						"{$h}/audio/e.mp3",
						"{$h}/audio/f.mp3",
						"{$h}/feed.xml",
						"{$h}/img/a.png",
						"{$h}/img/d.png",
						"{$h}/img/e.png",
						"{$h}/img/f.png",
						"{$h}/tr/a.vtt",
						"{$h}/tr/b.srt",
						"{$h}/tr/e.vtt",
						"{$h}/tr/f.srt",
					],
					epm_m_requested( $h ),
					'every copyable file was requested once; the deliberately chosen transcript and the JSON file were not'
				);

				// Per episode and kind.
				$a = epm_m_row( "med-{$p}-A" );
				$t->assert( $a['audio_id'] > 0 && $a['artwork_id'] > 0, 'A: audio and image copied' );
				$t->same( [ "{$h}/tr/a.vtt", '' ], [ $a['transcript_file'], $a['transcript_url'] ], 'A: the WebVTT file is copied and no longer linked at the old host' );
				$t->same( 'MANUAL EDIT of A', $a['transcript'], 'A: the edited transcript text is kept' );
				$b = epm_m_row( "med-{$p}-B" );
				$t->same( [ true, "{$h}/tr/b.srt", '' ], [ $b['audio_id'] > 0, $b['transcript_file'], $b['transcript_url'] ], 'B: audio and SRT copied' );
				$c = epm_m_row( "med-{$p}-C" );
				$t->same( [ true, '', "{$h}/tr/c.json" ], [ $c['audio_id'] > 0, $c['transcript_file'], $c['transcript_url'] ], 'C: the JSON transcript stays linked' );
				$dd = epm_m_row( "med-{$p}-D" );
				$t->same( [ true, true, '', 'https://captions.example.test/deliberate-d.vtt', 'MANUAL EDIT of D' ], [ $dd['audio_id'] > 0, $dd['artwork_id'] > 0, $dd['transcript_file'], $dd['transcript_url'], $dd['transcript'] ], 'D: the deliberately chosen transcript and the edited text are kept' );
				$e = epm_m_row( "med-{$p}-E" );
				$t->same( [ true, 0, "{$h}/img/e.png", '', "{$h}/tr/e.vtt" ], [ $e['audio_id'] > 0, $e['artwork_id'], $e['artwork_url'], $e['transcript_file'], $e['transcript_url'] ], 'E: image and WebVTT stay at the old host' );
				$f = epm_m_row( "med-{$p}-F" );
				$t->same( [ 0, "{$h}/audio/f.mp3", true, "{$h}/tr/f.srt" ], [ $f['audio_id'], $f['audio_url'], $f['artwork_id'] > 0, $f['transcript_file'] ], 'F: audio stays, image and SRT copied' );

				// What is still at the old host is listed per kind, with the reason.
				$t->same(
					[
						'audio'           => [ "{$name} F" . $epm_m_suffix ],
						'image'           => [ "{$name} E" . $epm_m_suffix ],
						'transcript_file' => [ "{$name} E" . $epm_m_suffix ],
						'transcript_link' => [ "{$name} C" . $epm_m_suffix ],
					],
					epm_m_remaining( $state ),
					'the job lists what stays at the old host per kind'
				);
				$t->assert( false !== strpos( epm_m_reason( $state, 'image', "{$name} E" . $epm_m_suffix ), '404' ), 'with the reason (image): ' . epm_m_reason( $state, 'image', "{$name} E" . $epm_m_suffix ) );
				$t->assert( false !== strpos( epm_m_reason( $state, 'audio', "{$name} F" . $epm_m_suffix ), '403' ), 'with the reason (audio): ' . epm_m_reason( $state, 'audio', "{$name} F" . $epm_m_suffix ) );
				$t->assert( '' !== epm_m_reason( $state, 'transcript_link', "{$name} C" . $epm_m_suffix ), 'and why the JSON file is not copied' );
				foreach ( (array) ( $state['remaining']['audio']['episodes'] ?? [] ) as $listed ) {
					$t->assert( (bool) preg_match( '/post\.php\?post=\d+&action=edit/', (string) ( $listed['edit'] ?? '' ) ), 'listed with a link to the episode' );
				}

				// FEED-N7: the move is not finished while files are at the old host.
				$t->same( 'done_with_problems', $state['status'] ?? '', 'the move ends with problems' );
				$t->same( [ false, false, 'self' ], epm_m_moved(), 'not marked as moved, not locked' );

				// Readiness names the same files.
				$t->same(
					[
						'Audio at the old host'            => [ "{$name} F" . $epm_m_suffix ],
						'Episode images at the old host'   => [ "{$name} E" . $epm_m_suffix ],
						'Transcript files at the old host' => [ "{$name} E" . $epm_m_suffix ],
						'Transcripts linked at the old host' => [ "{$name} C" . $epm_m_suffix ],
					],
					array_map(
						static function ( $check ) {
							return $check['items'];
						},
						epm_m_readiness( '/at the old host$/' )
					),
					'Readiness lists the remaining references per kind'
				);

				// The editor says where each transcript address came from.
				$editor = static function ( string $guid ): string {
					ob_start();
					( new \EPM\EpisodeMeta() )->box_transcript( get_post( epm_m_id( $guid ) ) );
					return wp_strip_all_tags( (string) ob_get_clean() );
				};
				$t->assert( false !== strpos( $editor( "med-{$p}-C" ), 'from the imported feed' ), 'C: the editor says the JSON transcript came with the import' );
				$t->assert( false === strpos( $editor( "med-{$p}-D" ), 'import' ) && false !== strpos( $editor( "med-{$p}-D" ), 'captions.example.test' ), 'D: the chosen transcript is not called imported' );

				// The rendered feed holds exactly those old-host addresses.
				$t->same( [ "{$h}/audio/f.mp3", "{$h}/img/e.png", "{$h}/tr/c.json", "{$h}/tr/e.vtt" ], epm_m_feed_urls( $h ), 'old-host addresses left in the feed' );

				// 4. Run the move again once the host answers: only the failed files are requested.
				$GLOBALS['epm_m_fail'] = [];
				EPM_Test_HTTP::$log    = [];
				$run   = epm_m_import(
					$feed,
					[
						'purpose'        => 'move',
						'download_media' => true,
						'confirm_owner'  => true,
					]
				);
				$state = $run['state'];
				$t->same( [ "{$h}/audio/f.mp3", "{$h}/feed.xml", "{$h}/img/e.png", "{$h}/tr/e.vtt" ], epm_m_requested( $h ), 'the run requests only what failed' );
				$t->same( [ 'transcript_link' => [ "{$name} C" . $epm_m_suffix ] ], epm_m_remaining( $state ), 'only the JSON link is left' );
				$t->same( [ "{$h}/tr/c.json" ], epm_m_feed_urls( $h ), 'only the JSON link points to the old host' );
				$t->same( 'done_with_problems', $state['status'] ?? '', 'still not finished silently' );
				$t->same( 'MANUAL EDIT of A', epm_m_row( "med-{$p}-A" )['transcript'], 'A: text kept on the second run' );
				$t->same( 'https://captions.example.test/deliberate-d.vtt', epm_m_row( "med-{$p}-D" )['transcript_url'], 'D: link kept on the second run' );

				// 5. Finishing anyway is an explicit choice.
				$confirmed = is_callable( [ ImportJob::class, 'confirm_move' ] ) ? ImportJob::confirm_move() : new WP_Error( 'missing', 'no confirmation' );
				$t->same( 'done', is_wp_error( $confirmed ) ? $confirmed->get_error_message() : $confirmed['status'], 'confirmed' );
				$t->same( [ true, true, 'self' ], epm_m_moved(), 'moved and locked after the confirmation' );
				$t->same( [ 'Transcripts linked at the old host' => [ "{$name} C" . $epm_m_suffix ] ], array_map( static function ( $check ) { return $check['items']; }, epm_m_readiness( '/at the old host$/' ) ), 'Readiness keeps naming the JSON link after the move' );
			} finally {
				epm_m_delete( "med-{$p}-" );
				epm_m_reset();
			}
		}
	);
}

$t->test(
	'a transcript address set on this site before 1.4 counts as chosen unless it is one of the feed item\'s own',
	static function ( EPM_Test_Runner $t ) {
		$p = 'm3c';
		$h = 'https://oldhost.example.test/' . $p;
		epm_m_reset();
		$feed = epm_m_show( $p );
		try {
			epm_m_import( $feed, [ 'purpose' => 'mirror' ] );
			// 1.3.0 data: links without any record of where they came from.
			foreach ( [ 'A', 'B', 'D' ] as $key ) {
				$id     = epm_m_id( "med-{$p}-{$key}" );
				$hashes = (array) get_post_meta( $id, '_epm_import_hash', true );
				unset( $hashes['transcript_url'] );
				update_post_meta( $id, '_epm_import_hash', $hashes );
				delete_post_meta( $id, '_epm_transcript_source' );
			}
			update_post_meta( epm_m_id( "med-{$p}-D" ), '_epm_transcript_url', 'https://elsewhere.example.test/d.vtt' );

			EPM_Test_HTTP::$log = [];
			epm_m_import(
				$feed,
				[
					'purpose'        => 'move',
					'download_media' => true,
					'confirm_owner'  => true,
				]
			);
			$t->same( [ "{$h}/tr/a.vtt", '' ], [ epm_m_row( "med-{$p}-A" )['transcript_file'], epm_m_row( "med-{$p}-A" )['transcript_url'] ], 'an address from the feed item is the import\'s: copied' );
			$t->same( "{$h}/tr/b.srt", epm_m_row( "med-{$p}-B" )['transcript_file'], 'SRT too' );
			$t->same( [ '', 'https://elsewhere.example.test/d.vtt' ], [ epm_m_row( "med-{$p}-D" )['transcript_file'], epm_m_row( "med-{$p}-D" )['transcript_url'] ], 'any other address is the site\'s choice: kept' );
			$t->same( 0, epm_m_count( "{$h}/tr/d.vtt" ) + epm_m_count( 'https://elsewhere.example.test/d.vtt' ), 'and not requested' );
		} finally {
			epm_m_delete( "med-{$p}-" );
			epm_m_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'FEED-N7: a move with files left at the old host' );
/* ------------------------------------------------------------------------- */

$t->test(
	'a move whose audio could not be copied is not finished: mode, moved_in and locked stay; retry or an explicit confirmation finishes it',
	static function ( EPM_Test_Runner $t ) {
		$feed  = 'https://feeds.example.test/synthetic/missing-audio.xml';
		$gone  = 'https://feeds.example.test/gone/half-moved-1.mp3';
		$other = 'https://feeds.example.test/media/half-moved-2.mp3';
		$move  = [
			'purpose'        => 'move',
			'download_media' => true,
			'confirm_owner'  => true,
		];
		$mirror = [
			'mode'     => 'external',
			'feed_url' => $feed,
			'sync'     => false,
			'redirect' => true,
		];
		try {
			epm_m_reset( $mirror );
			$run   = epm_m_import( $feed, $move );
			$state = $run['state'];
			$t->same( 'done_with_problems', $state['status'] ?? '', 'a distinct state' );
			$t->same( [ false, false, 'external' ], epm_m_moved(), 'mode, moved_in and locked unchanged' );
			$t->same( [ 'audio' => [ 'Missing audio episode' ] ], epm_m_remaining( $state ), 'what is still at the old host' );
			$t->same( $feed, Hosting::feed_redirect_target(), 'the site still redirects to the old feed' );

			// Retry once the file is back: only that file is requested, and the move finishes.
			EPM_Test_HTTP::$routes[ $gone ] = static function () {
				return EPM_Test_HTTP::response( 200, EPM_Test_HTTP::mp3( 5 ), [ 'content-type' => 'audio/mpeg' ] );
			};
			EPM_Test_HTTP::$log = [];
			$state = is_callable( [ ImportJob::class, 'retry' ] ) ? ImportJob::retry() : new WP_Error( 'missing', 'no retry' );
			$state = is_wp_error( $state ) ? [ 'status' => $state->get_error_message() ] : epm_m_steps( $state );
			$t->same( 'done', $state['status'] ?? '', 'the retry finishes' );
			$t->same( [ 1, 0 ], [ epm_m_count( $gone ), epm_m_count( $other ) ], 'only the failed file is requested again' );
			$t->same( [ true, true, 'self' ], epm_m_moved(), 'finish_move ran: moved, locked, hosted here' );
			$t->assert( (int) get_post_meta( epm_m_id( 'half-moved-1' ), '_epm_audio_id', true ) > 0, 'the audio is here' );
			unset( EPM_Test_HTTP::$routes[ $gone ] );
			epm_m_delete( 'half-moved-' );

			// The same failure, then the informed confirmation.
			epm_m_reset( $mirror );
			$state = epm_m_import( $feed, $move )['state'];
			$t->same( 'done_with_problems', $state['status'] ?? '' );
			$t->assert( false !== strpos( (string) ( $state['problems']['message'] ?? '' ), 'Missing audio episode' ) || in_array( 'Missing audio episode', epm_m_remaining( $state )['audio'] ?? [], true ), 'the confirmation names what stays behind' );
			$confirmed = is_callable( [ ImportJob::class, 'confirm_move' ] ) ? ImportJob::confirm_move() : new WP_Error( 'missing', 'no confirmation' );
			$t->same( 'done', is_wp_error( $confirmed ) ? $confirmed->get_error_message() : $confirmed['status'] );
			$t->same( [ true, true, 'self' ], epm_m_moved(), 'finish_move ran after the confirmation' );
			$t->same( [ 'Audio at the old host' => [ 'Missing audio episode' ] ], array_map( static function ( $check ) { return $check['items']; }, epm_m_readiness( '/at the old host$/' ) ), 'Readiness lists the audio left behind' );
			$again = is_callable( [ ImportJob::class, 'confirm_move' ] ) ? ImportJob::confirm_move() : null;
			$t->assert( is_wp_error( $again ), 'nothing to confirm twice' );
			epm_m_delete( 'half-moved-' );

			// A mirror with copies is never a move: it ends as done.
			epm_m_reset( $mirror );
			$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
			$t->same( [ 'done', [ 'audio' => [ 'Missing audio episode' ] ] ], [ $state['status'] ?? '', epm_m_remaining( $state ) ], 'mirror: done, the audio listed' );
			$t->same( [ false, false, 'external' ], epm_m_moved() );
			$refused = is_callable( [ ImportJob::class, 'confirm_move' ] ) ? ImportJob::confirm_move() : null;
			$t->assert( is_wp_error( $refused ), 'a mirror cannot be confirmed as a move' );
		} finally {
			unset( EPM_Test_HTTP::$routes[ $gone ] );
			epm_m_delete( 'half-moved-' );
			epm_m_reset();
			epm_m_restore( Hosting::OPTION );
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'IMP-04 / IMPB-N2 / IMPB-N4: what a download must be' );
/* ------------------------------------------------------------------------- */

$t->test(
	'a file above the size limit is refused, with and without Content-Length; nothing is left in the temp folder',
	static function ( EPM_Test_Runner $t ) {
		$cap = static function ( $bytes, $kind ) {
			return 'audio' === $kind ? 50000 : $bytes;
		};
		add_filter( 'epm_media_max_bytes', $cap, 10, 2 );
		try {
			foreach ( [ 'cap-len' => true, 'cap-nolen' => false ] as $name => $with_length ) {
				epm_m_reset();
				$body              = str_repeat( EPM_Test_HTTP::mp3( 1 ), 40 ); // 161 KB.
				[ $feed, $audio ] = epm_m_single(
					$name,
					static function () use ( $body, $with_length ) {
						return EPM_Test_HTTP::response( 200, $body, [ 'content-type' => 'audio/mpeg' ] + ( $with_length ? [ 'content-length' => (string) strlen( $body ) ] : [] ) );
					}
				);
				$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
				$row   = epm_m_row( "med-{$name}-1" );
				$t->same( [ 0, $audio ], [ $row['audio_id'], $row['audio_url'] ], $name . ': no copy, the old address stays' );
				$t->same( [ 'audio' => [ "Single {$name}" ] ], epm_m_remaining( $state ), $name . ': listed' );
				$t->assert( false !== stripos( epm_m_reason( $state, 'audio', "Single {$name}" ), 'limit' ), $name . ': the reason names the limit: ' . epm_m_reason( $state, 'audio', "Single {$name}" ) );
				$t->same( [], epm_m_temp_files(), $name . ': no temp files' );
				epm_m_delete( "med-{$name}-" );
			}
		} finally {
			remove_filter( 'epm_media_max_bytes', $cap, 10 );
			epm_m_reset();
		}
	}
);

$t->test(
	'a download shorter than its Content-Length is rejected, never stored as the episode\'s audio',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
		$full              = str_repeat( EPM_Test_HTTP::mp3( 1 ), 4 );
		[ $feed, $audio ] = epm_m_single(
			'truncated',
			static function () use ( $full ) {
				return EPM_Test_HTTP::response( 200, substr( $full, 0, (int) ( strlen( $full ) / 2 ) ), [ 'content-type' => 'audio/mpeg', 'content-length' => (string) strlen( $full ) ] );
			}
		);
		try {
			$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
			$row   = epm_m_row( 'med-truncated-1' );
			$t->same( [ 0, $audio ], [ $row['audio_id'], $row['audio_url'] ], 'the old address stays' );
			$reason = epm_m_reason( $state, 'audio', 'Single truncated' );
			$t->assert( (bool) preg_match( '/stopped|incomplete/i', $reason ), 'reported as incomplete: ' . $reason );
			$t->assert( epm_m_count( $audio ) >= 2, 'tried more than once (' . epm_m_count( $audio ) . ')' );
			$t->same( [], epm_m_temp_files(), 'no temp files' );
		} finally {
			epm_m_delete( 'med-truncated-' );
			epm_m_reset();
		}
	}
);

$t->test(
	'a web page, JSON or random bytes instead of audio are rejected with a reason that says so',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'html'  => [ '<!DOCTYPE html><html><head><title>Log in</title></head><body><form>Please log in.</form></body></html>', 'text/html; charset=UTF-8', 'mp3', '/web page/i' ],
			'json'  => [ '{"error":"not found","code":404}', 'application/json', 'mp3', '/JSON/' ],
			'octet' => [ random_bytes( 200000 ), 'application/octet-stream', '', '/not an audio file|not audio/i' ],
		];
		foreach ( $cases as $name => [ $body, $type, $ext, $expect ] ) {
			epm_m_reset();
			[ $feed, $audio ] = epm_m_single(
				'bad-' . $name,
				static function () use ( $body, $type ) {
					return EPM_Test_HTTP::response( 200, $body, [ 'content-type' => $type, 'content-length' => (string) strlen( $body ) ] );
				},
				$ext,
				strlen( $body )
			);
			try {
				$state  = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
				$row    = epm_m_row( "med-bad-{$name}-1" );
				$reason = epm_m_reason( $state, 'audio', "Single bad-{$name}" );
				$t->same( [ 0, $audio ], [ $row['audio_id'], $row['audio_url'] ], $name . ': not stored, the old address stays' );
				$t->assert( (bool) preg_match( $expect, $reason ), $name . ': ' . $reason );
				$t->same( [], epm_m_temp_files(), $name . ': no temp files' );
			} finally {
				epm_m_delete( "med-bad-{$name}-" );
			}
		}

		// The same for an episode image and a transcript file.
		epm_m_reset();
		$p    = 'm4w';
		$h    = 'https://oldhost.example.test/' . $p;
		$feed = epm_m_show( $p );
		$page = static function () {
			return EPM_Test_HTTP::response( 200, '<html><body>Sign in</body></html>', [ 'content-type' => 'text/html' ] );
		};
		$GLOBALS['epm_m_fail'] = [
			"{$h}/img/a.png" => $page,
			"{$h}/tr/a.vtt"  => $page,
		];
		try {
			$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
			$t->assert( (bool) preg_match( '/web page/i', epm_m_reason( $state, 'image', strtoupper( $p ) . ' A' ) ), 'image: ' . epm_m_reason( $state, 'image', strtoupper( $p ) . ' A' ) );
			$t->assert( (bool) preg_match( '/web page/i', epm_m_reason( $state, 'transcript_file', strtoupper( $p ) . ' A' ) ), 'transcript file: ' . epm_m_reason( $state, 'transcript_file', strtoupper( $p ) . ' A' ) );
			$t->same( [ 0, "{$h}/tr/a.vtt" ], [ epm_m_row( "med-{$p}-A" )['artwork_id'], epm_m_row( "med-{$p}-A" )['transcript_url'] ], 'both stay linked' );
		} finally {
			epm_m_delete( "med-{$p}-" );
			epm_m_reset();
		}
	}
);

$t->test(
	'HTTP 404, 403 and 500 are reported with the status and requested again by the next run',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ 404, 403, 500 ] as $code ) {
			epm_m_reset();
			$name             = 'status-' . $code;
			$status           = $code;
			[ $feed, $audio ] = epm_m_single(
				$name,
				static function () use ( &$status ) {
					return 200 === $status
						? EPM_Test_HTTP::response( 200, EPM_Test_HTTP::mp3( 5 ), [ 'content-type' => 'audio/mpeg' ] )
						: EPM_Test_HTTP::response( $status, '<html><body>no</body></html>', [ 'content-type' => 'text/html' ] );
				}
			);
			try {
				$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
				$t->assert( false !== strpos( epm_m_reason( $state, 'audio', "Single {$name}" ), (string) $code ), $code . ': ' . epm_m_reason( $state, 'audio', "Single {$name}" ) );
				$t->same( 0, epm_m_row( "med-{$name}-1" )['audio_id'], $code . ': not copied' );
				$status             = 200;
				EPM_Test_HTTP::$log = [];
				$state              = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
				$t->same( 1, epm_m_count( $audio ), $code . ': requested again' );
				$t->assert( epm_m_row( "med-{$name}-1" )['audio_id'] > 0, $code . ': copied on the next run' );
				$t->same( [], epm_m_remaining( $state ), $code . ': nothing left' );
			} finally {
				epm_m_delete( "med-{$name}-" );
			}
		}
		epm_m_reset();
	}
);

$t->test(
	'HTTP 429 with Retry-After pauses the job until then (no requests meanwhile); it continues afterwards',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
		$limited          = true;
		[ $feed, $audio ] = epm_m_single(
			'rate',
			static function () use ( &$limited ) {
				return $limited
					? EPM_Test_HTTP::response( 429, 'Slow down', [ 'content-type' => 'text/plain', 'retry-after' => '120' ] )
					: EPM_Test_HTTP::response( 200, EPM_Test_HTTP::mp3( 5 ), [ 'content-type' => 'audio/mpeg' ] );
			}
		);
		try {
			$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
			$t->same( 'waiting', $state['status'] ?? '', 'the job waits' );
			$until = (int) ( $state['wait_until'] ?? 0 );
			$t->assert( $until >= time() + 100 && $until <= time() + 130, 'until the time the host asked for (' . ( $until - time() ) . ' s)' );
			$next = wp_next_scheduled( ImportJob::CRON_HOOK );
			$t->assert( false !== $next && $next >= $until, 'the background run is scheduled for then' );
			$t->same( [], epm_m_remaining( $state ), 'nothing counted as failed' );

			EPM_Test_HTTP::$log = [];
			$state              = ImportJob::step( 8.0 );
			$t->same( [ 'waiting', 0 ], [ $state['status'] ?? '', epm_m_count( $audio ) ], 'a step before the time makes no request' );
			ImportJob::run_in_background();
			$t->same( 0, epm_m_count( $audio ), 'neither does the background run' );

			// The time has come.
			$limited = false;
			epm_m_edit_job(
				static function ( array $job ) {
					$job['wait_until'] = time() - 1;
					return $job;
				}
			);
			$state = epm_m_steps( ImportJob::step( 8.0 ) );
			$t->same( [ 'done', 1 ], [ $state['status'] ?? '', epm_m_count( $audio ) ], 'continues and copies' );
			$t->assert( epm_m_row( 'med-rate-1' )['audio_id'] > 0, 'copied' );

			// 503 with Retry-After waits too; a host that keeps asking is reported in the end.
			epm_m_delete( 'med-rate-' );
			epm_m_reset();
			$max = static function () {
				return 1;
			};
			add_filter( 'epm_media_max_waits', $max );
			EPM_Test_HTTP::$routes[ $audio ] = static function () {
				return EPM_Test_HTTP::response( 503, 'Busy', [ 'content-type' => 'text/plain', 'retry-after' => 'Wed, 21 Oct 2015 07:28:00 GMT' ] );
			};
			$state = epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] )['state'];
			$t->same( 'waiting', $state['status'] ?? '', '503 with Retry-After waits' );
			epm_m_edit_job(
				static function ( array $job ) {
					$job['wait_until'] = time() - 1;
					return $job;
				}
			);
			$state = epm_m_steps( ImportJob::step( 8.0 ) );
			remove_filter( 'epm_media_max_waits', $max );
			$t->same( 'done', $state['status'] ?? '', 'after the allowed waits the job goes on' );
			$t->assert( false !== strpos( epm_m_reason( $state, 'audio', 'Single rate' ), '503' ), 'the episode is listed with the status: ' . epm_m_reason( $state, 'audio', 'Single rate' ) );
		} finally {
			epm_m_delete( 'med-rate-' );
			epm_m_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'IMPB-N3 / IMPB-N1: an interrupted copy' );
/* ------------------------------------------------------------------------- */

$t->test(
	'a copy that dies keeps the episode\'s chapters and transcript; the next step copies the file once',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
		$feed     = 'https://oldhost.example.test/killed/feed.xml';
		$audio    = 'https://oldhost.example.test/killed/k1.mp3';
		$chapters = 'https://oldhost.example.test/killed/k1.json-chapters';
		$vtt      = 'https://oldhost.example.test/killed/k1.vtt';
		$xml      = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:podcast="https://podcastindex.org/namespace/1.0"><channel><title>Killed</title><link>https://oldhost.example.test/</link><description>x</description>'
			. '<item><title>Killed 1</title><guid isPermaLink="false">med-killed-1</guid><pubDate>' . gmdate( DATE_RSS, gmmktime( 8, 0, 0, 3, 1, 2026 ) ) . '</pubDate>'
			. '<enclosure url="' . $audio . '" length="4300" type="audio/mpeg"/><podcast:chapters url="' . $chapters . '" type="application/json+chapters"/><podcast:transcript url="' . $vtt . '" type="text/vtt"/></item></channel></rss>';
		EPM_Test_HTTP::$routes[ $feed ]     = static function () use ( $xml ) {
			return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
		};
		$die                                = true;
		EPM_Test_HTTP::$routes[ $audio ]    = static function ( $args, $url ) use ( &$die ) {
			if ( $die ) {
				throw new RuntimeException( 'The request died while copying the audio.' );
			}
			return epm_m_answer( $args, $url );
		};
		EPM_Test_HTTP::$routes[ $chapters ] = 'epm_m_answer';
		EPM_Test_HTTP::$routes[ $vtt ]      = 'epm_m_answer';
		try {
			$preview = ImportJob::preview( $feed );
			$state   = ImportJob::start( (string) $preview['token'], [ 'purpose' => 'move', 'download_media' => true ] );
			try {
				ImportJob::step( 8.0 );
				$t->assert( false, 'the copy should have died' );
			} catch ( RuntimeException $e ) {
				$t->assert( true );
			}
			$id = epm_m_id( 'med-killed-1' );
			$t->assert( $id > 0, 'the episode exists' );
			$t->same( 2, count( (array) get_post_meta( $id, '_epm_chapters', true ) ), 'its chapters were fetched before the copy' );
			$t->assert( false !== strpos( (string) get_post_meta( $id, '_epm_transcript', true ), 'Hello from the old host' ), 'and its transcript' );

			// The next step: the same episode, its file copied once.
			$die   = false;
			EPM_Test_HTTP::$log = [];
			$state = epm_m_steps( ImportJob::step( 8.0 ) );
			$t->same( 'done', $state['status'] ?? '', 'the move finishes' );
			$t->same( 1, count( epm_m_requested( $audio ) ), 'the audio requested again' );
			$t->same( [ $id ], array_values( array_map( 'intval', get_posts( [ 'post_type' => 'podcast_episode', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => '_epm_guid', 'meta_value' => 'med-killed-1' ] ) ) ), 'one episode' );
			$t->assert( (int) get_post_meta( $id, '_epm_audio_id', true ) > 0 && (int) get_post_meta( $id, '_epm_transcript_file_id', true ) > 0, 'audio and transcript file copied' );
			$t->same( 2, count( (array) get_post_meta( $id, '_epm_chapters', true ) ), 'chapters kept' );
			$t->same( [], epm_m_temp_files(), 'no temp files' );
		} finally {
			epm_m_delete( 'med-killed-' );
			epm_m_reset();
		}
	}
);

$t->test(
	'the same file is never copied twice: a copy already in the Media Library and an identical file left in uploads are reused',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
		[ $feed, $audio ] = epm_m_single( 'reuse', 'epm_m_answer' );
		$planted          = '';
		try {
			epm_m_import( $feed, [ 'purpose' => 'mirror' ] );
			// The file an earlier copy left in the episode's uploads folder
			// (a request that died before the file became an attachment).
			$uploads = wp_upload_dir( (string) get_post_field( 'post_date', epm_m_id( 'med-reuse-1' ) ) );
			wp_mkdir_p( $uploads['path'] );
			$planted = $uploads['path'] . '/audio.mp3';
			file_put_contents( $planted, EPM_Test_HTTP::mp3( 5 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			EPM_Test_HTTP::$log = [];
			epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] );
			$copy = (int) epm_m_row( 'med-reuse-1' )['audio_id'];
			$file = (string) get_attached_file( $copy );
			$t->same( $planted, $file, 'the identical file left behind is taken over, no "-1" copy' );
			$t->same( [ substr( $uploads['subdir'], 1 ) . '/audio.mp3' ], epm_m_uploads_named( 'audio' ), 'no second file' );

			// The episode loses its link (deleted by hand); the next import links the same attachment.
			delete_post_meta( epm_m_id( 'med-reuse-1' ), '_epm_audio_id' );
			EPM_Test_HTTP::$log = [];
			epm_m_import( $feed, [ 'purpose' => 'mirror', 'download_media' => true ] );
			$t->same( [ $copy, 0 ], [ (int) epm_m_row( 'med-reuse-1' )['audio_id'], epm_m_count( $audio ) ], 'reused without a download' );
		} finally {
			epm_m_delete( 'med-reuse-' );
			if ( '' !== $planted && file_exists( $planted ) ) {
				unlink( $planted ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			epm_m_reset();
		}
	}
);

$t->test(
	'a request that dies after the file reached uploads (separate process): no orphan, the next step copies it without a "-1" file',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
		$feed  = 'https://feeds.example.test/synthetic/missing-audio.xml';
		$child = __DIR__ . '/_media-child.php';
		try {
			$preview = ImportJob::preview( $feed );
			ImportJob::start( (string) $preview['token'], [ 'purpose' => 'mirror', 'download_media' => true ] );
			wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
			$t->same( [], epm_m_uploads_named( 'half-moved-' ), 'no such file before' );

			// Item 1's audio is gone (404); item 2 is copied in the child, which
			// runs out of memory right after the file is moved into uploads.
			$run = WP_CLI::runcommand(
				'eval-file ' . escapeshellarg( $child ) . ' die-after-move',
				[
					'launch'     => true,
					'return'     => 'all',
					'exit_error' => false,
				]
			);
			$t->assert( 0 !== $run->return_code && false !== stripos( $run->stderr . $run->stdout, 'memory' ), 'the child died of memory exhaustion: ' . substr( $run->stderr . $run->stdout, -300 ) );
			wp_cache_flush();

			$t->same( [], epm_m_uploads_named( 'half-moved-' ), 'no orphan file in uploads' );
			$t->same( [], epm_m_temp_files(), 'no temp files' );
			$lock = (string) $GLOBALS['wpdb']->get_var( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'epm_import_lock'" );
			$t->same( '', $lock, 'the lock was released' );
			$t->assert( false !== wp_next_scheduled( ImportJob::CRON_HOOK ), 'the continuation is scheduled' );
			$job = ImportJob::get();
			$t->same( 'running', $job['status'] ?? '', 'the job goes on' );
			$t->assert( false !== stripos( wp_json_encode( $job['media'] ?? [] ), 'memory' ), 'the interruption is recorded with its reason' );

			$state = epm_m_steps( ImportJob::step( 8.0 ) );
			$t->same( 'done', $state['status'] ?? '', 'the next run finishes' );
			$copy = (int) get_post_meta( epm_m_id( 'half-moved-2' ), '_epm_audio_id', true );
			$t->same( 'half-moved-2.mp3', $copy > 0 ? basename( (string) get_attached_file( $copy ) ) : '(none)', 'copied without a "-1" duplicate' );
			$t->same( 1, count( epm_m_uploads_named( 'half-moved-' ) ), 'one file' );
		} finally {
			epm_m_delete( 'half-moved-' );
			// What a failing run may leave behind.
			$base = wp_upload_dir( null, false )['basedir'];
			foreach ( epm_m_uploads_named( 'half-moved-' ) as $left ) {
				unlink( $base . '/' . $left ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			epm_m_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
WP_CLI::log( 'Temp files and WP-CLI' );
/* ------------------------------------------------------------------------- */

$t->test(
	'leftover download files of the plugin are removed; files of other software are not',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
		$dir   = get_temp_dir();
		$stale = $dir . 'epm-media-stale0000-abcdef.part';
		$fresh = $dir . 'epm-media-fresh0000-abcdef.part';
		$other = $dir . 'not-epm-media-0000.part';
		foreach ( [ $stale, $fresh, $other ] as $file ) {
			file_put_contents( $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		touch( $stale, time() - 3 * HOUR_IN_SECONDS );
		touch( $other, time() - 3 * HOUR_IN_SECONDS );
		try {
			do_action( ImportJob::CLEANUP_HOOK );
			$t->same( [ false, true, true ], [ file_exists( $stale ), file_exists( $fresh ), file_exists( $other ) ], 'only the plugin\'s stale file goes' );
		} finally {
			foreach ( [ $stale, $fresh, $other ] as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}
	}
);

$t->test(
	'wp podcast status shows the import; wp podcast cancel stops it',
	static function ( EPM_Test_Runner $t ) {
		epm_m_reset();
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
		$feed = 'https://feeds.example.test/synthetic/missing-audio.xml';
		try {
			$preview = ImportJob::preview( $feed );
			ImportJob::start( (string) $preview['token'], [ 'purpose' => 'move', 'download_media' => true ] );
			$status = $cli( 'podcast status' );
			$t->assert( false !== stripos( $status->stdout, 'import' ) && false !== strpos( $status->stdout, 'running' ) && false !== strpos( $status->stdout, '0 of 2' ), 'status shows the running import: ' . $status->stdout );

			$help = ( new ReflectionClass( \EPM\Cli::class ) )->hasMethod( 'cancel' );
			$t->assert( $help, 'there is a cancel command' );
			if ( $help ) {
				$cancel = $cli( 'podcast cancel' );
				$t->same( 0, $cancel->return_code, 'cancel: ' . $cancel->stderr );
				$t->same( 'cancelled', ImportJob::get()['status'] ?? '', 'the job is cancelled' );
				$t->assert( false !== strpos( $cli( 'podcast status' )->stdout, 'cancelled' ), 'and status says so' );
				$again = $cli( 'podcast cancel' );
				$t->assert( 0 !== $again->return_code, 'nothing to cancel twice' );
			}

			// A move that ended with problems: status lists what is still at the old host.
			epm_m_reset();
			epm_m_import( $feed, [ 'purpose' => 'move', 'download_media' => true, 'confirm_owner' => true ] );
			$status = $cli( 'podcast status' );
			$t->assert( false !== strpos( $status->stdout, 'done_with_problems' ) && false !== strpos( $status->stdout, 'Missing audio episode' ), 'status names the episode whose audio stayed: ' . $status->stdout );
		} finally {
			epm_m_delete( 'half-moved-' );
			epm_m_reset();
		}
	}
);

/* ------------------------------------------------------------------------- */
/* Put the site back                                                         */
/* ------------------------------------------------------------------------- */

EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$offline = false;
foreach ( array_keys( $GLOBALS['epm_m_options'] ) as $epm_m_name ) {
	epm_m_restore( $epm_m_name );
}
wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
Feed::flush_cache();

$t->finish();
