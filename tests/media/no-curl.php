<?php
/** Safe refusal of media copies when PHP cannot enforce streaming limits. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/integration/lib.php';
if ( 'production' === wp_get_environment_type() || ! getenv( 'EPM_TEST_SITE' ) ) { WP_CLI::error( 'Marked disposable test site required.' ); }
if ( WpOrg\Requests\Transport\Curl::test() ) { WP_CLI::error( 'Run with -d disable_functions=curl_exec.' ); }
$t = new EPM_Test_Runner();
$t->test( 'No cURL: media copying fails before any host request or partial file', static function ( $t ) {
	$url = 'https://feeds.example.test/no-curl.mp3';
	$requests = count( EPM_Test_HTTP::$log );
	$state = EPM\MediaDownload::run( EPM\MediaDownload::fresh( $url, 'audio' ), microtime( true ) + 2 );
	try {
		$t->same( 'failed', $state['result'], 'safe terminal failure' );
		$t->same( 'curl_required', $state['reason'], 'actionable capability failure' );
		$t->same( $url, $state['url'], 'original file address preserved' );
		$t->same( '', $state['file'], 'no unwatched download starts' );
		$t->same( $requests, count( EPM_Test_HTTP::$log ), 'no host contacted' );
		$t->assert( false !== strpos( $state['message'], 'cURL' ), 'explains the recovery requirement' );
	} finally { EPM\MediaDownload::discard( $state ); }
} );
$t->finish();
