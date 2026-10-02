<?php
/**
 * One request of the import budget test (see run.sh): checks a synthetic
 * paged feed, continues reading it, or steps the import, and prints what
 * this request cost as JSON.
 *
 *   wp eval-file tests/perf/import-budget.php <phase> <pages> <per-page> [notes-bytes]
 *
 * Phases: preview (the first request of "Check feed"), more (one further
 * request), start, step, reset. Each phase runs in a process of its own,
 * like one admin-ajax request, so the peak memory is the request's own.
 * Pages are generated when they are requested (like a host would), so
 * the feed never sits in memory as a whole. Only for test sites.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\ImportJob;

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to run on a production site.' );
}
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}

$epm_b_phase = (string) ( $args[0] ?? '' );
$epm_b_pages = max( 1, (int) ( $args[1] ?? 10 ) );
$epm_b_per   = max( 1, (int) ( $args[2] ?? 100 ) );
$epm_b_notes = max( 10, (int) ( $args[3] ?? 1500 ) );
$epm_b_base  = 'https://feeds.example.test/perf/' . $epm_b_pages . 'x' . $epm_b_per . '/';

EPM_Test_HTTP::$offline = true;
add_filter(
	'pre_http_request',
	static function ( $pre, $r, $url ) {
		return false !== strpos( (string) $url, 'wp-cron.php' ) ? new WP_Error( 'epm_perf', 'No cron.' ) : $pre;
	},
	1,
	3
);
// Short requests, so the feed is read over several of them (the admin
// screen's requests last ten seconds; a page here takes milliseconds).
add_filter(
	'epm_import_request_seconds',
	static function () {
		return (float) ( getenv( 'EPM_PERF_REQUEST_SECONDS' ) ?: 1.0 );
	}
);
add_filter(
	'epm_import_max_pages',
	static function () use ( $epm_b_pages ) {
		return max( 50, $epm_b_pages );
	}
);

$epm_b_para = '<p>' . str_repeat( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', (int) ceil( $epm_b_notes / 57 ) ) . '</p>';
$epm_b_para = substr( $epm_b_para, 0, $epm_b_notes - 4 ) . '</p>';

for ( $p = 1; $p <= $epm_b_pages; $p++ ) {
	EPM_Test_HTTP::$routes[ $epm_b_base . 'p' . $p . '.xml' ] = static function () use ( $p, $epm_b_pages, $epm_b_per, $epm_b_base, $epm_b_para ) {
		$all   = $epm_b_pages * $epm_b_per;
		$items = '';
		for ( $i = 0; $i < $epm_b_per; $i++ ) {
			$k      = $all - ( ( $p - 1 ) * $epm_b_per + $i );
			$items .= '<item><title>Perf episode ' . $k . '</title><guid isPermaLink="false">perf-' . $k . '</guid>'
				. '<pubDate>' . gmdate( DATE_RSS, 1262304000 + $k * 3600 ) . '</pubDate>'
				. '<description><![CDATA[' . $epm_b_para . ']]></description>'
				. '<enclosure url="https://feeds.example.test/media/perf-' . $k . '.mp3" length="4000" type="audio/mpeg"/>'
				. '<itunes:duration>00:05</itunes:duration></item>';
		}
		$next = $p < $epm_b_pages ? '<atom:link href="' . $epm_b_base . 'p' . ( $p + 1 ) . '.xml" rel="next" type="application/rss+xml"/>' : '';
		$body = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
			. '<title>Perf show</title><link>https://show.example.test/</link><description>Budget test</description><language>en</language>'
			. $next . $items . '</channel></rss>';
		// A slow host (EPM_PERF_LATENCY_MS per page).
		usleep( 1000 * (int) getenv( 'EPM_PERF_LATENCY_MS' ) );
		return EPM_Test_HTTP::response( 200, $body, [ 'content-type' => 'application/rss+xml' ] );
	};
}

/**
 * Delete the perf episodes and the job.
 *
 * @return void
 */
function epm_b_reset(): void {
	global $wpdb;

	ImportJob::cancel();
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epm_guid' AND meta_value LIKE 'perf-%'" );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

// WP-CLI lifts the memory limit; a request on a stock php-fpm has 128M.
ini_set( 'memory_limit', (string) ( getenv( 'EPM_PERF_MEMORY_LIMIT' ) ?: '128M' ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

gc_collect_cycles();
$epm_b_before = memory_get_usage();
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$epm_b_start = microtime( true );
$epm_b_out   = [ 'phase' => $epm_b_phase ];

switch ( $epm_b_phase ) {
	case 'reset':
		epm_b_reset();
		break;

	case 'preview':
		$summary = ImportJob::preview( $epm_b_base . 'p1.xml' );
		break;

	case 'more':
		$summary = ImportJob::preview_more( (string) ( ImportJob::get()['token'] ?? '' ) );
		break;

	case 'start':
		$state = ImportJob::start( (string) ( ImportJob::get()['token'] ?? '' ), [ 'purpose' => 'mirror' ] );
		break;

	case 'step':
		$state = ImportJob::step( 8.0 );
		break;

	default:
		WP_CLI::error( 'Unknown phase.' );
}

$epm_b_out['seconds'] = round( microtime( true ) - $epm_b_start, 3 );
// The request's own memory: its peak above what WordPress needed to boot.
$epm_b_out['peak_mb']   = round( ( memory_get_peak_usage() - $epm_b_before ) / MB_IN_BYTES, 1 );
$epm_b_out['exact']     = function_exists( 'memory_reset_peak_usage' );
$epm_b_out['limit']     = ini_get( 'memory_limit' );
if ( isset( $summary ) ) {
	$epm_b_out['error']    = is_wp_error( $summary ) ? $summary->get_error_message() : '';
	$epm_b_out['episodes'] = is_wp_error( $summary ) ? 0 : (int) $summary['episodes'];
	$epm_b_out['loading']  = ! is_wp_error( $summary ) && ! empty( $summary['catalog']['loading'] );
	$epm_b_out['complete'] = ! is_wp_error( $summary ) && ! empty( $summary['catalog']['complete'] );
	$epm_b_out['pages']    = is_wp_error( $summary ) ? 0 : (int) $summary['catalog']['pages'];
}
if ( isset( $state ) ) {
	$epm_b_out['error']  = is_wp_error( $state ) ? $state->get_error_message() : (string) $state['error'];
	$epm_b_out['status'] = is_wp_error( $state ) ? '' : $state['status'];
	$epm_b_out['done']   = is_wp_error( $state ) ? 0 : (int) $state['done'];
	$epm_b_out['total']  = is_wp_error( $state ) ? 0 : (int) $state['total'];
}

echo 'EPM_PERF ' . wp_json_encode( $epm_b_out ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
