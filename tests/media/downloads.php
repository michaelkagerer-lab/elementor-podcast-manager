<?php
/**
 * Media downloads over real sockets, from the local media host that
 * tests/media/run.sh starts (tests/fixtures/mediaserver.py). The test-only
 * mu-plugin tests/fixtures/mu-plugins/epm-test-loopback.php lets the safe
 * HTTP functions reach exactly that host and port.
 *
 *   wp eval-file tests/media/downloads.php            the suite
 *   wp eval-file tests/media/downloads.php memory N   copy an N-byte audio file
 *                                                     (run.sh: under a 128M limit)
 *   wp eval-file tests/media/downloads.php disk-full  run.sh: with a 20 MB temp folder
 *   wp eval-file tests/media/downloads.php uploads-full <dir>  run.sh: with a full uploads month folder
 *
 * Feeds come from EPM_Test_HTTP::$routes (no request); their enclosures
 * point to the media host. Episodes use GUIDs starting with "dl-" and are
 * deleted again.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Hosting;
use EPM\ImportJob;
use EPM\Importer;

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to run on a production site.' );
}
require_once dirname( __DIR__ ) . '/integration/lib.php';
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}

$epm_md_origin = (string) getenv( 'EPM_TEST_MEDIA_ORIGIN' );
$epm_md_log    = (string) getenv( 'EPM_TEST_MEDIA_LOG' );
if ( ! preg_match( '/^127\.0\.0\.1:\d+$/', $epm_md_origin ) || '' === $epm_md_log ) {
	WP_CLI::error( 'Start this through tests/media/run.sh (it runs the media host).' );
}
if ( ! has_filter( 'http_allowed_safe_ports' ) ) {
	WP_CLI::error( 'The test-only mu-plugin epm-test-loopback.php is not installed (tests/bin/setup-wp.sh installs it).' );
}

EPM_Test_HTTP::$offline = true;
EPM_Test_HTTP::$routes  = [];
// No background requests during the run.
add_filter(
	'pre_http_request',
	static function ( $pre, $r, $url ) {
		return false !== strpos( (string) $url, 'wp-cron.php' ) ? new WP_Error( 'epm_test', 'No cron.' ) : $pre;
	},
	1,
	3
);
wp_set_current_user( (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 ) );

/**
 * URL on the media host.
 *
 * @param string $path Path.
 * @return string
 */
function epm_md_url( string $path ): string {
	return 'http://' . getenv( 'EPM_TEST_MEDIA_ORIGIN' ) . '/' . ltrim( $path, '/' );
}

/**
 * Bytes the media host serves for [offset, offset + length) (the same
 * frames as mediaserver.py).
 *
 * @param int $offset Offset.
 * @param int $length Length.
 * @return string
 */
function epm_md_frames( int $offset, int $length ): string {
	$first = intdiv( $offset, 144 );
	$last  = intdiv( $offset + $length + 143, 144 );
	$out   = '';
	for ( $n = $first; $n < $last; $n++ ) {
		$out .= "\xFF\xFB\x18\xC0" . pack( 'N', $n ) . str_repeat( "\0", 136 );
	}

	return substr( $out, $offset - $first * 144, $length );
}

/**
 * Requests the media host saw for a path (oldest first).
 *
 * @param string $part Part of the path.
 * @return array<int, array<string, mixed>>
 */
function epm_md_requests( string $part ): array {
	// The host logs a request when it notices its end (a hang-up a moment
	// after the client let go).
	usleep( 500000 );
	$out = [];
	foreach ( (array) file( (string) getenv( 'EPM_TEST_MEDIA_LOG' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$entry = json_decode( (string) $line, true );
		if ( is_array( $entry ) && false !== strpos( (string) $entry['path'], $part ) ) {
			$out[] = $entry;
		}
	}

	return $out;
}

/**
 * A one-episode feed (served in-process) whose audio is on the media host.
 *
 * @param string $name   Case (GUID dl-<name>).
 * @param string $audio  Audio URL.
 * @param int    $length Enclosure length.
 * @return string Feed URL.
 */
function epm_md_feed( string $name, string $audio, int $length = 0 ): string {
	$feed = 'https://oldhost.example.test/dl/' . $name . '.xml';
	$xml  = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Download ' . $name . '</title><link>https://oldhost.example.test/</link><description>x</description>'
		. '<item><title>Download ' . $name . '</title><guid isPermaLink="false">dl-' . $name . '</guid><pubDate>' . gmdate( DATE_RSS, gmmktime( 8, 0, 0, 4, 2, 2026 ) ) . '</pubDate>'
		. '<enclosure url="' . esc_url( $audio ) . '" length="' . $length . '" type="audio/mpeg"/></item></channel></rss>';
	EPM_Test_HTTP::$routes[ $feed ] = static function () use ( $xml ) {
		return EPM_Test_HTTP::response( 200, $xml, [ 'content-type' => 'application/rss+xml' ] );
	};

	return $feed;
}

/**
 * Check and start an import with copies; returns the first client state.
 *
 * @param string $feed    Feed URL.
 * @param string $purpose mirror or move.
 * @return array
 */
function epm_md_start( string $feed, string $purpose = 'mirror' ): array {
	ImportJob::cancel();
	global $wpdb;
	$wpdb->delete( $wpdb->options, [ 'option_name' => 'epm_import_lock' ] );
	$preview = ImportJob::preview( $feed );
	if ( is_wp_error( $preview ) ) {
		return [ 'status' => $preview->get_error_message() ];
	}
	$state = ImportJob::start(
		(string) $preview['token'],
		[
			'purpose'        => $purpose,
			'download_media' => true,
			'confirm_owner'  => true,
		]
	);

	return is_wp_error( $state ) ? [ 'status' => $state->get_error_message() ] : $state;
}

/**
 * Step until the job stops; the wall time of each step.
 *
 * @param array $state  Client state.
 * @param float $budget Budget per step.
 * @return array{state: array, steps: float[]}
 */
function epm_md_steps( array $state, float $budget = 8.0 ): array {
	$steps = [];
	for ( $i = 0; $i < 200 && 'running' === ( $state['status'] ?? '' ); $i++ ) {
		$t       = microtime( true );
		$state   = ImportJob::step( $budget );
		$steps[] = round( microtime( true ) - $t, 2 );
	}

	return [
		'state' => $state,
		'steps' => $steps,
	];
}

/**
 * Reason listed for the audio of the only episode.
 *
 * @param array $state Client state.
 * @return string
 */
function epm_md_reason( array $state ): string {
	return (string) ( $state['remaining']['audio']['episodes'][0]['reason'] ?? '' );
}

/**
 * Delete the episodes of the run and their files.
 *
 * @param string $prefix GUID prefix.
 * @return void
 */
function epm_md_delete( string $prefix = 'dl-' ): void {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epm_guid' AND meta_value LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $ids as $id ) {
		foreach ( get_children( [ 'post_parent' => (int) $id, 'post_type' => 'attachment', 'fields' => 'ids' ] ) as $attachment ) {
			wp_delete_attachment( (int) $attachment, true );
		}
		wp_delete_post( (int) $id, true );
	}
	ImportJob::cancel();
}

/**
 * Download files of the plugin in the temp folder.
 *
 * @return string[]
 */
function epm_md_temp(): array {
	return array_map( 'basename', glob( get_temp_dir() . 'epm-media-*' ) ?: [] );
}

/**
 * The episode's audio attachment (0 when none).
 *
 * @param string $name Case.
 * @return int
 */
function epm_md_audio( string $name ): int {
	return (int) get_post_meta( Importer::find_guid( 'dl-' . $name ), '_epm_audio_id', true );
}

$epm_md_mode = (string) ( $args[0] ?? '' );

/* ------------------------------------------------------------------------- */
/* Modes run.sh starts in processes of their own                             */
/* ------------------------------------------------------------------------- */

if ( 'feed-loop' === $epm_md_mode ) {
	$t = new EPM_Test_Runner();
	$saved = [];
	foreach ( [ Hosting::OPTION, Hosting::STATE_OPTION, 'cron', 'epm_import_lock' ] as $name ) {
		$saved[ $name ] = epm_test_option_snapshot( $name );
	}
	try {
		$t->test( 'FEED-N2: a real permanent redirect to the local feed stops before a loop is followed', static function ( EPM_Test_Runner $t ) {
			$host = epm_md_url( '/redirect/301/' . rawurlencode( EPM\Feed::url() ) );
			delete_option( 'epm_import_lock' );
			update_option( Hosting::OPTION, array_merge( Hosting::defaults(), [ 'mode' => 'external', 'feed_url' => $host, 'sync' => true, 'redirect' => true ] ) );
			delete_option( Hosting::STATE_OPTION );
			$t->same( $host, Hosting::feed_redirect_target(), 'precondition: the local feed redirects to the host' );
			$result = Hosting::sync( true );
			$t->same( 'ok', $result['status'], 'a permanent move here is handled successfully' );
			$t->same( 'self', Hosting::get( 'mode' ), 'hosting switches to this website' );
			$t->same( '', Hosting::feed_redirect_target(), 'the permanent redirect back to the host is removed' );
			$t->assert( ! Hosting::sync_enabled(), 'the host is no longer synchronized' );
			$requests = epm_md_requests( '/redirect/301/' );
			$t->same( 1, count( $requests ), 'the host is requested once; the loop is never followed' );
		} );
	} finally {
		foreach ( $saved as $name => $value ) { epm_test_option_restore( $name, $value ); }
	}
	$t->finish();
	return;
}

if ( 'memory' === $epm_md_mode ) {
	// One copy of a large audio file in a request with a stock memory limit.
	$bytes = max( 1, (int) ( $args[1] ?? 62914560 ) );
	ini_set( 'memory_limit', (string) ( getenv( 'EPM_MEDIA_MEMORY_LIMIT' ) ?: '128M' ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
	add_filter(
		'epm_media_request_seconds',
		static function () {
			return 50.0;
		}
	);
	epm_md_delete();
	$name  = 'memory-' . $bytes;
	$state = epm_md_start( epm_md_feed( $name, epm_md_url( '/id3/' . $bytes . '/' . $name . '.mp3' ), $bytes ) );
	gc_collect_cycles();
	$before = memory_get_usage();
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$t      = microtime( true );
	$result = epm_md_steps( $state, 50.0 );
	$id     = epm_md_audio( $name );
	$file   = $id > 0 ? (string) get_attached_file( $id ) : '';
	$out    = [
		'status'  => $result['state']['status'] ?? '',
		'copied'  => $id > 0 && is_file( $file ) && filesize( $file ) === $bytes,
		'mime'    => $id > 0 ? (string) get_post_mime_type( $id ) : '',
		'length'  => $id > 0 ? (string) ( wp_get_attachment_metadata( $id )['length_formatted'] ?? '' ) : '',
		'seconds' => round( microtime( true ) - $t, 2 ),
		'peak_mb' => round( ( memory_get_peak_usage() - $before ) / MB_IN_BYTES, 1 ),
		'exact'   => function_exists( 'memory_reset_peak_usage' ),
		'limit'   => ini_get( 'memory_limit' ),
		'reason'  => epm_md_reason( $result['state'] ),
	];
	epm_md_delete();
	echo 'EPM_MEDIA ' . wp_json_encode( $out ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

if ( 'disk-full' === $epm_md_mode || 'uploads-full' === $epm_md_mode ) {
	$t = new EPM_Test_Runner();
	if ( 'disk-full' === $epm_md_mode ) {
		$t->test(
			'IMPB-N2: a temp folder too small for the file: refused before the download when the size is known, stopped when the disk fills up otherwise; never stored cut off',
			static function ( EPM_Test_Runner $t ) {
				epm_md_delete();
				$state = epm_md_steps( epm_md_start( epm_md_feed( 'full-len', epm_md_url( '/len/52428800/full-len.mp3' ) ) ) )['state'];
				$sent  = array_sum( array_column( epm_md_requests( '/full-len.mp3' ), 'sent' ) );
				$t->same( 0, epm_md_audio( 'full-len' ), 'not stored' );
				$t->assert( (bool) preg_match( '/disk space/i', epm_md_reason( $state ) ), 'with Content-Length: ' . epm_md_reason( $state ) );
				$t->assert( $sent < 8 * MB_IN_BYTES, 'stopped right after the headers (' . size_format( $sent ) . ' sent)' );

				epm_md_delete();
				$state = epm_md_steps( epm_md_start( epm_md_feed( 'full-chunked', epm_md_url( '/chunked/52428800/full-chunked.mp3' ) ) ) )['state'];
				$t->same( 0, epm_md_audio( 'full-chunked' ), 'not stored' );
				$t->assert( (bool) preg_match( '/written to the disk|disk space/i', epm_md_reason( $state ) ), 'without Content-Length: ' . epm_md_reason( $state ) );
				$t->same( [], epm_md_temp(), 'no partial file left' );
				epm_md_delete();
			}
		);
	} else {
		$epm_md_dir = (string) ( $args[1] ?? '' );
		$t->test(
			'IMPB-N2: an uploads folder that is full: the copy fails, no partial file stays in it, the episode keeps its address',
			static function ( EPM_Test_Runner $t ) use ( $epm_md_dir ) {
				$dir = $epm_md_dir;
				epm_md_delete();
				$audio = epm_md_url( '/len/3145728/uploads-full.mp3' );
				$state = epm_md_steps( epm_md_start( epm_md_feed( 'uploads-full', $audio ) ) )['state'];
				$t->same( 0, epm_md_audio( 'uploads-full' ), 'not stored' );
				$t->same( $audio, (string) get_post_meta( Importer::find_guid( 'dl-uploads-full' ), '_epm_audio_url', true ), 'the address stays' );
				$t->assert( '' !== epm_md_reason( $state ), 'reported: ' . epm_md_reason( $state ) );
				$t->same( [], array_values( array_diff( scandir( $dir ) ?: [], [ '.', '..' ] ) ), 'nothing left in ' . $dir );
				$t->same( [], epm_md_temp(), 'no temp files' );
				epm_md_delete();
			}
		);
	}
	EPM_Test_HTTP::$routes = [];
	$t->finish();
	return;
}

/* ------------------------------------------------------------------------- */
/* The suite                                                                 */
/* ------------------------------------------------------------------------- */

$t = new EPM_Test_Runner();

$t->test(
	'resuming a changed enclosure uses If-Range and never combines different file versions',
	static function ( EPM_Test_Runner $t ) {
		$bytes = 1000000;
		$path = '/changing/250000/' . $bytes . '/' . wp_generate_uuid4() . '.mp3';
		$state = \EPM\MediaDownload::fresh( epm_md_url( $path ), 'audio', $bytes );
		try {
			$state = \EPM\MediaDownload::run( $state, microtime( true ) + 1.2 );
			$t->same( 'partial', $state['result'], 'the first version stops at the request budget' );
			$state = \EPM\MediaDownload::run( $state, microtime( true ) + 3 );
			$t->same( 'complete', $state['result'], 'the replacement arrives completely' );
			$tries = epm_md_requests( $path );
			$resumes = array_values( array_filter( $tries, static fn( $request ) => '' !== $request['range'] ) );
			$t->assert( count( $resumes ) >= 1 && '"media-' . $bytes . '-0"' === ( $resumes[0]['if_range'] ?? '' ), 'the resume sends the original strong validator: ' . wp_json_encode( $tries ) );
			$t->same( sha1( epm_md_frames( 144 * 123, $bytes ) ), is_file( $state['file'] ) ? sha1_file( $state['file'] ) : '', 'the file consists entirely of the new version' );
		} finally {
			\EPM\MediaDownload::discard( $state );
		}
	}
);

$t->test(
	'If-Range uses modification dates instead of weak ETags and refuses unverifiable partial copies',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ 'date', 'weak', 'novalidator' ] as $kind ) {
			$bytes = 500000;
			$path = '/' . $kind . '/250000/' . $bytes . '/' . wp_generate_uuid4() . '.mp3';
			$state = \EPM\MediaDownload::fresh( epm_md_url( $path ), 'audio', $bytes );
			try {
				$state = \EPM\MediaDownload::run( $state, microtime( true ) + 1.2 );
				if ( 'novalidator' === $kind ) {
					$t->same( 'failed', $state['result'], 'an unverifiable partial copy is not published' );
					$t->same( 'no_validator', $state['reason'], 'the missing validator is explained' );
					$t->assert( ! is_file( $state['file'] ), 'the unsafe partial file is removed' );
					continue;
				}
				$t->same( 'partial', $state['result'], $kind . ': stopped at the budget' );
				$t->same( 'Wed, 30 Sep 2026 06:00:00 GMT', $state['validator'], $kind . ': a usable modification date' );
				$state = \EPM\MediaDownload::run( $state, microtime( true ) + 3 );
				$t->same( 'complete', $state['result'], $kind . ': safely resumed' );
				$t->same( sha1( epm_md_frames( 0, $bytes ) ), sha1_file( $state['file'] ), $kind . ': byte-identical' );
			} finally {
				\EPM\MediaDownload::discard( $state );
			}
		}
	}
);

if ( 'if-range' === $epm_md_mode ) {
	$t->finish();
	return;
}

WP_CLI::log( 'IMP-04: limits while the file streams' );

$t->test(
	'a file above the size limit is stopped while it streams: with Content-Length right after the headers, without one (chunked) at the limit',
	static function ( EPM_Test_Runner $t ) {
		$cap = static function ( $bytes, $kind ) {
			return 'audio' === $kind ? 2 * MB_IN_BYTES : $bytes;
		};
		add_filter( 'epm_media_max_bytes', $cap, 10, 2 );
		try {
			foreach ( [ 'cap-len' => '/len/52428800/cap-len.mp3', 'cap-chunked' => '/chunked/52428800/cap-chunked.mp3' ] as $name => $path ) {
				epm_md_delete();
				$t0     = microtime( true );
				$result = epm_md_steps( epm_md_start( epm_md_feed( $name, epm_md_url( $path ) ) ) );
				$secs   = microtime( true ) - $t0;
				$sent   = array_sum( array_column( epm_md_requests( $path ), 'sent' ) );
				$t->same( 0, epm_md_audio( $name ), $name . ': not stored' );
				$t->assert( false !== stripos( epm_md_reason( $result['state'] ), 'limit' ), $name . ': ' . epm_md_reason( $result['state'] ) );
				$t->assert( $sent < 8 * MB_IN_BYTES, $name . ': the host sent ' . size_format( $sent ) . ' of 50 MB' );
				$t->same( 1, count( epm_md_requests( $path ) ), $name . ': requested once (a limit is no reason to try again)' );
				$t->assert( $secs < 10, $name . ': ' . round( $secs, 1 ) . ' s' );
				$t->same( [], epm_md_temp(), $name . ': no temp files' );
				WP_CLI::log( sprintf( '    %s: stopped after %s sent, %.2f s', $name, size_format( $sent, 1 ), $secs ) );
			}
		} finally {
			remove_filter( 'epm_media_max_bytes', $cap, 10 );
			epm_md_delete();
		}
	}
);

$t->test(
	'a host that stops sending is cut off by the low-speed limit, tried again, then reported; no step waits longer than its budget',
	static function ( EPM_Test_Runner $t ) {
		$slow = static function () {
			return [
				'bytes'   => KB_IN_BYTES,
				'seconds' => 2,
			];
		};
		add_filter( 'epm_media_low_speed', $slow );
		try {
			epm_md_delete();
			$t0     = microtime( true );
			$result = epm_md_steps( epm_md_start( epm_md_feed( 'stall', epm_md_url( '/stall/stall.mp3' ) ) ) );
			$secs   = microtime( true ) - $t0;
			$tries  = epm_md_requests( '/stall/stall.mp3' );
			$t->same( 0, epm_md_audio( 'stall' ), 'not stored' );
			$t->assert( false !== stripos( epm_md_reason( $result['state'] ), 'stopped sending' ), epm_md_reason( $result['state'] ) );
			$t->same( 3, count( $tries ), 'three attempts' );
			$t->assert( max( array_column( $tries, 'seconds' ) ) < 4.5, 'each cut off within the 2-second window (+ the next check): ' . wp_json_encode( array_column( $tries, 'seconds' ) ) );
			$t->assert( max( $result['steps'] ) < 9.5, 'no step longer than its 8-second budget (+1.5): ' . wp_json_encode( $result['steps'] ) );
			$t->same( [], epm_md_temp(), 'no temp files' );
			WP_CLI::log( sprintf( '    stalled host: %d attempts of %s s, %.1f s in all, steps %s', count( $tries ), wp_json_encode( array_column( $tries, 'seconds' ) ), $secs, wp_json_encode( $result['steps'] ) ) );
		} finally {
			remove_filter( 'epm_media_low_speed', $slow );
			epm_md_delete();
		}
	}
);

$t->test(
	'a stall is reported as one also when each step is shorter than the low-speed window (the import screen\'s short steps)',
	static function ( EPM_Test_Runner $t ) {
		epm_md_delete();
		$result = epm_md_steps( epm_md_start( epm_md_feed( 'stall-short', epm_md_url( '/stall/stall-short.mp3' ) ) ), 4.0 );
		$t->same( 0, epm_md_audio( 'stall-short' ), 'not stored' );
		$t->assert( false !== stripos( epm_md_reason( $result['state'] ), 'stopped sending' ), 'reported as a stall: ' . epm_md_reason( $result['state'] ) );
		$t->assert( count( $result['steps'] ) <= 4 && max( $result['steps'] ) < 5.5, 'within a few 4-second steps: ' . wp_json_encode( $result['steps'] ) );
		$t->same( [], epm_md_temp(), 'no temp files' );
		epm_md_delete();
	}
);

$t->test(
	'a slow file larger than one request continues with Range requests over several steps and arrives byte-identical; a host without Range support is reported',
	static function ( EPM_Test_Runner $t ) {
		$short = static function () {
			return 2.0;
		};
		add_filter( 'epm_media_request_seconds', $short );
		try {
			epm_md_delete();
			$bytes  = 1500000;
			$path   = '/slow/250000/' . $bytes . '/slow.mp3';
			$t0     = microtime( true );
			$result = epm_md_steps( epm_md_start( epm_md_feed( 'slow', epm_md_url( $path ), $bytes ) ) );
			$secs   = microtime( true ) - $t0;
			$tries  = epm_md_requests( $path );
			$id     = epm_md_audio( 'slow' );
			$file   = $id > 0 ? (string) get_attached_file( $id ) : '';
			$t->same( 'done', $result['state']['status'] ?? '', 'done' );
			$t->assert( count( $tries ) >= 3, 'over several requests (' . count( $tries ) . ')' );
			$t->same( '', (string) $tries[0]['range'], 'the first request asks for the whole file' );
			$t->assert( count( $tries ) > 1 && 0 === strpos( (string) $tries[1]['range'], 'bytes=' ) && 206 === (int) $tries[1]['status'], 'the next ones continue with Range: ' . wp_json_encode( array_slice( $tries, 1, 1 ) ) );
			$t->assert( array_sum( array_column( $tries, 'sent' ) ) < 2 * $bytes, 'no part sent twice over (' . array_sum( array_column( $tries, 'sent' ) ) . ' bytes for ' . $bytes . ')' );
			$t->assert( max( $result['steps'] ) < 4.0, 'no step longer than its 2-second download budget (+2): ' . wp_json_encode( $result['steps'] ) );
			$t->same( sha1( epm_md_frames( 0, $bytes ) ), is_file( $file ) ? sha1_file( $file ) : '', 'byte-identical' );
			$t->same( 'audio/mpeg', $id > 0 ? (string) get_post_mime_type( $id ) : '', 'stored as audio' );
			$t->same( [], epm_md_temp(), 'no temp files' );
			WP_CLI::log( sprintf( '    slow file: %d requests, steps %s, %.1f s in all', count( $tries ), wp_json_encode( $result['steps'] ), $secs ) );

			epm_md_delete();
			$path   = '/norange/250000/' . $bytes . '/norange.mp3';
			$result = epm_md_steps( epm_md_start( epm_md_feed( 'norange', epm_md_url( $path ), $bytes ) ) );
			$t->same( 0, epm_md_audio( 'norange' ), 'without Range support: not stored' );
			$t->assert( false !== strpos( epm_md_reason( $result['state'] ), 'Range' ), 'reported: ' . epm_md_reason( $result['state'] ) );
			$t->assert( count( epm_md_requests( $path ) ) <= 5, 'given up after a few requests (' . count( epm_md_requests( $path ) ) . ')' );
			$t->same( [], epm_md_temp(), 'no temp files' );
		} finally {
			remove_filter( 'epm_media_request_seconds', $short );
			epm_md_delete();
		}
	}
);

WP_CLI::log( 'IMPB-N4 and HTTP answers from a real host' );

$t->test(
	'a login page, random bytes, HTTP 404 and a redirect to a private address are reported with what happened',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'html'     => [ '/html/page.mp3', '/web page/i' ],
			'octet'    => [ '/octet/blob', '/not an audio file|not audio/i' ],
			'missing'  => [ '/status/404/gone.mp3', '/404/' ],
			'redirect' => [ '/redirect/302/' . rawurlencode( 'http://169.254.169.254/latest/meta-data.mp3' ), '/not a public web address/i' ],
		];
		foreach ( $cases as $name => [ $path, $expect ] ) {
			epm_md_delete();
			$state = epm_md_steps( epm_md_start( epm_md_feed( $name, epm_md_url( $path ) ) ) )['state'];
			$t->same( 0, epm_md_audio( $name ), $name . ': not stored' );
			$t->assert( (bool) preg_match( $expect, epm_md_reason( $state ) ), $name . ': ' . epm_md_reason( $state ) );
		}
		$t->same( [], epm_md_temp(), 'no temp files' );

		// 429 from the host: the job waits instead of failing.
		epm_md_delete();
		$state = epm_md_steps( epm_md_start( epm_md_feed( 'rate', epm_md_url( '/status/429/rate.mp3' ) ) ) )['state'];
		$t->same( 'waiting', $state['status'] ?? '', '429: the job waits' );
		$t->assert( (int) $state['wait_until'] >= time() + 100, '429: for the time the host asked (Retry-After: 120)' );
		epm_md_delete();
	}
);

WP_CLI::log( 'A move from a real host' );

$t->test(
	'a move through Hosting & import with a slow file finishes only once the file is here',
	static function ( EPM_Test_Runner $t ) {
		$short = static function () {
			return 2.0;
		};
		add_filter( 'epm_media_request_seconds', $short );
		$before = get_option( Hosting::OPTION );
		try {
			epm_md_delete();
			$state = epm_md_start( epm_md_feed( 'move', epm_md_url( '/slow/400000/1200000/move.mp3' ), 1200000 ), 'move' );
			$first = ImportJob::step( 8.0 );
			$t->assert( 'running' === $first['status'] && null !== $first['current'], 'after one step the copy is in progress: ' . wp_json_encode( $first['current'] ) );
			$t->same( false, (bool) epm()->settings->get( 'moved_in' ), 'not moved yet' );
			$state = epm_md_steps( $first )['state'];
			$t->same( 'done', $state['status'] ?? '', 'done' );
			$t->same( true, (bool) epm()->settings->get( 'moved_in' ), 'moved once the file is here' );
		} finally {
			remove_filter( 'epm_media_request_seconds', $short );
			$settings             = epm()->settings->all();
			$settings['moved_in'] = false;
			$settings['locked']   = false;
			update_option( 'epm_podcast_settings', epm()->settings->sanitize( $settings ) );
			update_option( Hosting::OPTION, $before );
			epm_md_delete();
		}
	}
);

EPM_Test_HTTP::$routes  = [];
EPM_Test_HTTP::$offline = false;
wp_clear_scheduled_hook( ImportJob::CRON_HOOK );

$t->finish();
