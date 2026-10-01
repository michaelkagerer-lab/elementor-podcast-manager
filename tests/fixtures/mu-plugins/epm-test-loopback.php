<?php
/**
 * Plugin Name: EPM test loopback media host
 * Description: Test sites only (installed by tests/bin/setup-wp.sh). Lets the safe HTTP functions reach the local media server of tests/media/ (tests/fixtures/mediaserver.py), and nothing else on this machine: one host and port, named in the environment variable EPM_TEST_MEDIA_ORIGIN ("127.0.0.1:8962"). Without the variable it does nothing.
 *
 * wp_safe_remote_get() refuses loopback addresses and ports other than
 * 80, 443 and 8080, which is right for a live site and the reason the
 * plugin's own code never allows them. The media suite needs real sockets
 * (streaming limits, stalls, Range requests), so this test-only file opens
 * exactly that one origin.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Never on a live site.
if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
	return;
}

$epm_test_origin = (string) getenv( 'EPM_TEST_MEDIA_ORIGIN' );
if ( ! preg_match( '/^127\.0\.0\.1:(\d{2,5})$/', $epm_test_origin, $epm_test_match ) ) {
	return;
}
$epm_test_port = (int) $epm_test_match[1];

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host, $url ) use ( $epm_test_port ) {
		return ( '127.0.0.1' === $host && $epm_test_port === (int) wp_parse_url( $url, PHP_URL_PORT ) ) ? true : $external;
	},
	10,
	3
);
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports, $host = '' ) use ( $epm_test_port ) {
		if ( '127.0.0.1' === $host ) {
			$ports[] = $epm_test_port;
		}
		return $ports;
	},
	10,
	2
);
