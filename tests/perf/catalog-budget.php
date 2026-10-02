<?php
/**
 * One request of the catalog budget test (see run.sh): builds and serves
 * the podcast feed, builds the readiness report or runs the upgrade on a
 * synthetic catalog, and prints what this request cost as JSON.
 *
 *   wp eval-file tests/perf/catalog-budget.php catalog <episodes> [transcript-kb] [mixed]
 *   wp eval-file tests/perf/catalog-budget.php feed <limit> cold|warm|304 [etag]
 *   wp eval-file tests/perf/catalog-budget.php readiness
 *   wp eval-file tests/perf/catalog-budget.php upgrade
 *   wp eval-file tests/perf/catalog-budget.php upgrade-step     (needs 1.4: the queued batches)
 *   wp eval-file tests/perf/catalog-budget.php reset
 *
 * Each phase runs in a process of its own, like one web request, with the
 * memory_limit of a stock php-fpm (EPM_PERF_MEMORY_LIMIT, default 128M);
 * the peak is reported above what WordPress needed to boot. "feed" serves
 * the feed the way /podcast/feed/ does and streams the body into a
 * temporary file, which is then checked with XMLReader (well-formed, item
 * count). Only for test sites.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to run on a production site.' );
}
require_once __DIR__ . '/catalog.php';
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}
EPM_Test_HTTP::$offline = true;
add_filter(
	'pre_http_request',
	static function ( $pre, $r, $url ) {
		return false !== strpos( (string) $url, 'wp-cron.php' ) ? new WP_Error( 'epm_perf', 'No cron.' ) : $pre;
	},
	1,
	3
);

global $wpdb;
$epm_c_phase = (string) ( $args[0] ?? '' );
$epm_c_out   = [ 'phase' => $epm_c_phase ];

/**
 * Set the feed episode limit.
 *
 * @param int $limit Limit (0 = unlimited).
 * @return void
 */
function epm_c_limit( int $limit ): void {
	$settings = epm()->settings;
	update_option( \EPM\PodcastSettings::OPTION, $settings->sanitize( array_merge( $settings->all(), [ 'feed_limit' => $limit ] ) ) );
}

// Setup phases run without a budget.
if ( 'catalog' === $epm_c_phase ) {
	$epm_c_count = (int) ( $args[1] ?? 1000 );
	$epm_c_kb    = (int) ( $args[2] ?? 0 );
	$epm_c_mixed = '0' !== (string) ( $args[3] ?? '1' );
	$epm_c_t     = microtime( true );
	$epm_c_out['episodes']      = epm_perf_catalog( $epm_c_count, $epm_c_kb, $epm_c_mixed );
	$epm_c_out['distributable'] = epm_perf_distributable( $epm_c_count, $epm_c_mixed );
	$epm_c_out['seconds']       = round( microtime( true ) - $epm_c_t, 2 );
	echo 'EPM_PERF ' . wp_json_encode( $epm_c_out ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}
if ( 'reset' === $epm_c_phase ) {
	$epm_c_out['deleted'] = epm_perf_catalog_reset();
	echo 'EPM_PERF ' . wp_json_encode( $epm_c_out ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

// Preparation outside the measured part.
$epm_c_mode = '';
if ( 'feed' === $epm_c_phase ) {
	$epm_c_limit = (int) ( $args[1] ?? 500 );
	$epm_c_mode  = (string) ( $args[2] ?? 'cold' );
	if ( (int) epm()->settings->get( 'feed_limit' ) !== $epm_c_limit ) {
		epm_c_limit( $epm_c_limit );
	}
	if ( 'cold' === $epm_c_mode ) {
		\EPM\Feed::flush_cache();
	}
	if ( '304' === $epm_c_mode ) {
		$_SERVER['HTTP_IF_NONE_MATCH'] = (string) ( $args[3] ?? '' );
	}
	$epm_c_file   = wp_tempnam( 'epm-perf-feed' );
	$epm_c_handle = fopen( $epm_c_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$epm_c_status = 200;
	add_filter(
		'status_header',
		static function ( $header, $code ) use ( &$epm_c_status ) {
			$epm_c_status = (int) $code;
			return $header;
		},
		10,
		2
	);
}
if ( 'upgrade' === $epm_c_phase ) {
	// "Updated from 1.2.0": what the first request after an update sees,
	// with every catalog episode's seconds out of date.
	$wpdb->query( "UPDATE {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id SET m.meta_value = '0' WHERE m.meta_key = '_epm_duration_seconds' AND p.post_name LIKE 'perf-cat-%'" );
	update_option( 'epm_version', '1.2.0' );
}

wp_cache_flush();
gc_collect_cycles();
// WP-CLI lifts the memory limit; a request on a stock php-fpm has 128M.
ini_set( 'memory_limit', (string) ( getenv( 'EPM_PERF_MEMORY_LIMIT' ) ?: '128M' ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
$epm_c_before = memory_get_usage();
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
global $wpdb;
$epm_c_queries = (int) $wpdb->num_queries;
$epm_c_start   = microtime( true );

switch ( $epm_c_phase ) {
	case 'feed':
		ob_start(
			static function ( $buffer ) use ( $epm_c_handle ) {
				fwrite( $epm_c_handle, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				return '';
			},
			65536
		);
		epm()->feed->serve_feed();
		ob_end_flush();
		break;

	case 'readiness':
		$epm_c_report = \EPM\Readiness::report();
		break;

	case 'upgrade':
		epm()->maybe_upgrade();
		break;

	case 'upgrade-step':
		$epm_c_state = \EPM\Upgrade::run( 30.0 );
		break;

	default:
		WP_CLI::error( 'Unknown phase.' );
}

$epm_c_out['seconds'] = round( microtime( true ) - $epm_c_start, 3 );
$epm_c_out['peak_mb'] = round( ( memory_get_peak_usage() - $epm_c_before ) / MB_IN_BYTES, 1 );
$epm_c_out['queries'] = (int) $wpdb->num_queries - $epm_c_queries;
$epm_c_out['exact']   = function_exists( 'memory_reset_peak_usage' );
$epm_c_out['limit']   = ini_get( 'memory_limit' );

// Checks after the measurement.
ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
if ( 'feed' === $epm_c_phase ) {
	fclose( $epm_c_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	$epm_c_out['status'] = $epm_c_status;
	$epm_c_out['bytes']  = (int) filesize( $epm_c_file );
	$epm_c_out['items']  = 0;
	$epm_c_out['well_formed'] = false;
	if ( $epm_c_out['bytes'] > 0 ) {
		$epm_c_reader = new XMLReader();
		$epm_c_ok     = $epm_c_reader->open( $epm_c_file, null, LIBXML_NONET );
		$epm_c_guids  = [];
		libxml_use_internal_errors( true );
		while ( $epm_c_ok && ( $epm_c_ok = $epm_c_reader->read() ) ) {
			if ( XMLReader::ELEMENT === $epm_c_reader->nodeType && 'item' === $epm_c_reader->name ) {
				++$epm_c_out['items'];
			}
			if ( XMLReader::ELEMENT === $epm_c_reader->nodeType && 'guid' === $epm_c_reader->name ) {
				$epm_c_guids[] = $epm_c_reader->readString();
			}
		}
		$epm_c_out['well_formed'] = empty( libxml_get_errors() ) && $epm_c_out['items'] > 0;
		$epm_c_out['first_guid']  = (string) ( $epm_c_guids[0] ?? '' );
		$epm_c_out['last_guid']   = (string) ( end( $epm_c_guids ) ?: '' );
		$epm_c_out['unique']      = count( array_unique( $epm_c_guids ) ) === count( $epm_c_guids );
		$epm_c_reader->close();
	}
	$epm_c_out['etag'] = (string) epm()->feed->get_document()['etag'];
	wp_delete_file( $epm_c_file );
}
if ( isset( $epm_c_report ) ) {
	$epm_c_out['ready']    = (bool) $epm_c_report['ready'];
	$epm_c_out['errors']   = (int) $epm_c_report['errors'];
	$epm_c_out['warnings'] = (int) $epm_c_report['warnings'];
	$epm_c_out['checks']   = count( $epm_c_report['checks'] );
}
if ( 'upgrade' === $epm_c_phase || 'upgrade-step' === $epm_c_phase ) {
	$epm_c_out['version'] = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'epm_version'" );
	if ( class_exists( 'EPM\\Upgrade' ) ) {
		$epm_c_out['pending'] = \EPM\Upgrade::pending();
	}
	// Catalog episodes whose seconds the upgrade has not synced yet.
	$epm_c_out['stale'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_epm_duration_seconds' AND m.meta_value <> '600' AND p.post_name LIKE 'perf-cat-%'" );
}

echo 'EPM_PERF ' . wp_json_encode( $epm_c_out ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
