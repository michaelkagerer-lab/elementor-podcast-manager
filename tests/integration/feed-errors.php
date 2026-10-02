<?php
/** UX-N9: actionable feed failures with separate technical details. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/lib.php';
$t = new EPM_Test_Runner();
$url = 'https://feeds.example.test/error-copy.xml';
$cases = [
	'timeout' => [ 'cURL error 28: Operation timed out', 'did not answer in time' ],
	'connection' => [ 'cURL error 6: Could not resolve host', 'could not be reached' ],
	'address' => [ 'A valid URL was not provided.', 'public feed address' ],
];
try {
	foreach ( $cases as $name => [ $technical, $phrase ] ) {
		$t->test( 'UX-N9: ' . $name . ' explains recovery without a raw HTTP error', static function ( $t ) use ( $url, $technical, $phrase ) {
			EPM_Test_HTTP::$routes[ $url ] = static fn() => new WP_Error( 'http_request_failed', $technical );
			$result = EPM\Hosting::fetch( $url );
			$t->assert( is_wp_error( $result ), 'fetch failed' );
			$t->assert( false !== strpos( $result->get_error_message(), $phrase ), 'actionable copy: ' . $result->get_error_message() );
			$t->assert( false === strpos( $result->get_error_message(), $technical ), 'technical error stays out of main message' );
			$t->same( $technical, $result->get_error_data()['details'] ?? '', 'technical detail is retained separately' );
		} );
	}
	$t->test( 'UX-N9: delivery checks keep HTTP diagnostics separate from the recovery message', static function ( $t ) {
		$feed_url = EPM\Hosting::public_feed_url();
		EPM_Test_HTTP::$routes[ $feed_url ] = static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: diagnostic probe' );
		try {
			$checks = EPM\AdminPages::server_check();
			$feed = $checks[0];
			$t->assert( false === strpos( $feed['message'], 'cURL' ), 'primary diagnostic message has recovery copy' );
			$t->same( 'cURL error 28: diagnostic probe', $feed['details'] ?? '', 'technical details retained' );
		} finally { unset( EPM_Test_HTTP::$routes[ $feed_url ] ); }
	} );
} finally { unset( EPM_Test_HTTP::$routes[ $url ] ); }
$t->finish();
