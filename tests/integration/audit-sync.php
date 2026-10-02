<?php
/** Targeted regressions for sync failures, scheduled episodes and Retry-After. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/lib.php';
use EPM\Hosting;
use EPM\ImportJob;
use EPM\Episodes;

$saved = [];
foreach ( [ Hosting::OPTION, Hosting::STATE_OPTION, ImportJob::OPTION, 'epm_import_lock', 'epm_version', 'epm_activation_redirect', 'rewrite_rules', 'cron' ] as $name ) {
	$saved[ $name ] = epm_test_option_snapshot( $name );
}
$user = get_current_user_id();
wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
$url = 'https://feeds.example.test/audit-sync.xml';
$ids = [];
$t = new EPM_Test_Runner();

function epm_audit_sync_feed( array $dates, string $extra = '' ): string {
	$xml = '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><title>Sync regression probe</title><description>Disposable</description>' . $extra;
	foreach ( $dates as $guid => $date ) {
		$xml .= '<item><title>Probe ' . epm_esc_xml( $guid ) . '</title><guid>' . epm_esc_xml( $guid ) . '</guid><pubDate>' . gmdate( 'r', $date ) . '</pubDate><enclosure url="https://cdn.example.test/' . epm_esc_xml( $guid ) . '.mp3" type="audio/mpeg" length="1024" /></item>';
	}
	return $xml . '</channel></rss>';
}

try {
	delete_option( 'epm_import_lock' );
	update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), [ 'mode' => 'external', 'feed_url' => $url, 'sync' => true, 'redirect' => false ] ) ) );
	delete_option( Hosting::STATE_OPTION );
	$t->test( 'FEED-N1: the settings save verifies a host feed before enabling the permanent redirect', static function ( EPM_Test_Runner $t ) use ( $url ) {
		$hosting = new Hosting();
		$hosting->register_settings();
		$urls = [];
		try {
			update_option( Hosting::OPTION, Hosting::defaults() );
			foreach ( [ 'missing' => 404, 'webpage' => 200 ] as $case => $status ) {
				$bad = 'https://feeds.example.test/audit-switch-' . $case . '.xml';
				$urls[] = $bad;
				EPM_Test_HTTP::$routes[ $bad ] = static fn() => EPM_Test_HTTP::response( $status, '<html>not a podcast</html>' );
				update_option( Hosting::OPTION, array_merge( Hosting::defaults(), [ 'mode' => 'external', 'feed_url' => $bad, 'redirect' => true ] ) );
				$t->same( 'self', Hosting::get( 'mode' ), 'unverified ' . $case . ' cannot switch hosting' );
				$t->same( '', Hosting::feed_redirect_target(), 'unverified ' . $case . ' cannot enable a 301' );
			}
			$valid = 'https://feeds.example.test/audit-switch-valid.xml';
			$urls[] = $valid;
			EPM_Test_HTTP::$routes[ $valid ] = static fn() => EPM_Test_HTTP::response( 200, epm_audit_sync_feed( [ 'switch-probe' => time() - DAY_IN_SECONDS ] ) );
			update_option( Hosting::OPTION, array_merge( Hosting::defaults(), [ 'mode' => 'external', 'feed_url' => $valid, 'redirect' => true ] ) );
			$t->same( 'external', Hosting::get( 'mode' ), 'a verified feed can switch hosting' );
			$t->same( $valid, Hosting::feed_redirect_target(), 'the redirect uses the verified feed' );
		} finally {
			remove_filter( 'pre_update_option_' . Hosting::OPTION, [ Hosting::class, 'validate_settings_save' ], 10 );
			foreach ( $urls as $candidate ) { unset( EPM_Test_HTTP::$routes[ $candidate ] ); }
			update_option( Hosting::OPTION, array_merge( Hosting::defaults(), [ 'mode' => 'external', 'feed_url' => $url, 'sync' => true, 'redirect' => false ] ) );
		}
	} );

	$t->test( 'SYNC-N3 / FEED-N5: an announced 404, invalid RSS or unrelated show cannot replace the feed or redirect', static function ( EPM_Test_Runner $t ) use ( $url, &$ids ) {
		$guid = 'audit-move-' . wp_generate_uuid4();
		foreach ( [ 'missing' => [ 404, '' ], 'invalid' => [ 200, '<html>not RSS</html>' ], 'unrelated' => [ 200, epm_audit_sync_feed( [ 'unrelated-' . $guid => time() - DAY_IN_SECONDS ] ) ] ] as $case => [ $status, $body ] ) {
			$candidate = 'https://feeds.example.test/audit-candidate-' . $case . '.xml';
			update_option( Hosting::OPTION, array_merge( Hosting::defaults(), [ 'mode' => 'external', 'feed_url' => $url, 'sync' => true, 'redirect' => true ] ) );
			delete_option( Hosting::STATE_OPTION );
			EPM_Test_HTTP::$routes[ $url ] = static fn() => EPM_Test_HTTP::response( 200, epm_audit_sync_feed( [ $guid => time() - DAY_IN_SECONDS ], '<itunes:new-feed-url>' . epm_esc_xml( $candidate ) . '</itunes:new-feed-url>' ) );
			EPM_Test_HTTP::$routes[ $candidate ] = static fn() => EPM_Test_HTTP::response( $status, $body );
			try {
				$result = Hosting::sync( true );
				$ids[] = EPM\Importer::find_guid( $guid );
				$t->same( 'ok', $result['status'], 'the current feed can still sync: ' . $case );
				$t->same( $url, Hosting::get( 'feed_url' ), 'the current source is kept: ' . $case );
				$t->same( $url, Hosting::feed_redirect_target(), 'the public redirect is kept: ' . $case );
				$t->assert( false !== strpos( $result['message'], 'current address was kept' ), 'the rejected move is explained: ' . $case );
			} finally { unset( EPM_Test_HTTP::$routes[ $candidate ] ); }
		}
	} );

	$t->test( 'UX-N12: a completed mirror is connected until its first successful sync', static function ( EPM_Test_Runner $t ) use ( $url ) {
		update_option( Hosting::OPTION, array_merge( Hosting::defaults(), [ 'mode' => 'external', 'feed_url' => $url, 'sync' => true, 'redirect' => true ] ) );
		delete_option( Hosting::STATE_OPTION );
		update_option( ImportJob::OPTION, [ 'status' => 'done', 'options' => [ 'purpose' => 'mirror' ] ] );
		$report = EPM\Readiness::report();
		$messages = implode( ' ', array_column( $report['checks'], 'message' ) );
		$html = EPM\Readiness::render_html( $report );
		$t->assert( false !== strpos( $messages, 'first automatic sync' ), 'the next sync is explained' );
		$t->assert( false === strpos( $messages, 'Not synced yet' ), 'the completed import is not presented as missing' );
		$t->assert( false !== strpos( $html, '>Connected<' ) && false === strpos( $html, '>In sync<' ), 'the badge reports a connection rather than an unverified sync' );
		Hosting::update_state( [ 'last_success' => time(), 'status' => 'ok', 'message' => 'Checked successfully.' ] );
		$t->assert( false !== strpos( EPM\Readiness::render_html(), '>In sync<' ), 'the badge changes after a successful sync' );
	} );

	$t->test( 'LIFE-N4: reactivation schedules a running import without changing its stored progress', static function ( EPM_Test_Runner $t ) {
		$job = [ 'status' => 'running', 'token' => 'reactivation-probe', 'position' => 3, 'total' => 10, 'options' => [ 'purpose' => 'mirror' ] ];
		update_option( ImportJob::OPTION, $job );
		EPM\Lifecycle::deactivate();
		$t->same( false, wp_next_scheduled( ImportJob::CRON_HOOK ), 'deactivation removes continuation' );
		EPM\Lifecycle::activate();
		$next = wp_next_scheduled( ImportJob::CRON_HOOK );
		$t->assert( false !== $next && $next <= time() + 2 * MINUTE_IN_SECONDS, 'reactivation resumes within the next minute' );
		$t->same( $job, ImportJob::get(), 'token and progress remain unchanged' );
		EPM\Lifecycle::activate();
		$t->same( $next, wp_next_scheduled( ImportJob::CRON_HOOK ), 'reactivation does not duplicate continuation' );
		wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
		update_option( ImportJob::OPTION, array_merge( $job, [ 'cancelled' => true ] ) );
		epm()->resume_interrupted_import();
		$t->same( false, wp_next_scheduled( ImportJob::CRON_HOOK ), 'an explicitly stopped import stays stopped' );
		delete_option( ImportJob::OPTION );
	} );

	$t->test( 'SYNC-N1: an item failure remains visible and an unchanged feed retries it', static function ( EPM_Test_Runner $t ) use ( $url, &$ids ) {
		$prefix = 'audit-sync-' . wp_generate_uuid4();
		$good = $prefix . '-good';
		$bad = $prefix . '-bad';
		$xml = epm_audit_sync_feed( [ $good => time() - DAY_IN_SECONDS, $bad => time() - 2 * DAY_IN_SECONDS ] );
		EPM_Test_HTTP::$routes[ $url ] = static function ( $request ) use ( $xml ) {
			$headers = array_change_key_case( (array) ( $request['headers'] ?? [] ), CASE_LOWER );
			return EPM_Test_HTTP::response( empty( $headers['if-none-match'] ) ? 200 : 304, $xml, [ 'etag' => '"unchanged-probe"' ] );
		};
		$fail = static fn( $empty, $post ) => 'Probe ' . $bad === ( $post['post_title'] ?? '' ) ? true : $empty;
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		try {
			$result = Hosting::sync( true );
			$t->same( 'partial', $result['status'], 'the partial import is not reported as successful' );
			$t->same( '', Hosting::state()['etag'], 'validators are withheld so failed items can retry' );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $fail, 10 );
		}
		$retry = Hosting::sync();
		$t->same( 'ok', $retry['status'], 'the retry succeeds without changing the remote feed' );
		foreach ( [ $good, $bad ] as $guid ) {
			$id = EPM\Importer::find_guid( $guid );
			$t->assert( $id > 0, 'the retried feed contains ' . $guid );
			$ids[] = $id;
		}
	} );

	$t->test( 'SYNC-N5: host date changes reschedule episodes and missing scheduled episodes stop publishing', static function ( EPM_Test_Runner $t ) use ( $url, &$ids ) {
		$guid = 'audit-future-' . wp_generate_uuid4();
		$anchor = $guid . '-anchor';
		$date = time() + 3 * DAY_IN_SECONDS;
		$dates = [ $guid => $date, $anchor => time() - DAY_IN_SECONDS ];
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( &$dates ) { return EPM_Test_HTTP::response( 200, epm_audit_sync_feed( $dates ) ); };
		Hosting::sync( true );
		$id = EPM\Importer::find_guid( $guid );
		$ids[] = $id;
		$ids[] = EPM\Importer::find_guid( $anchor );
		$t->same( 'future', get_post_status( $id ), 'the future episode is scheduled' );
		$dates[ $guid ] = $date + DAY_IN_SECONDS;
		Hosting::sync( true );
		$t->same( gmdate( 'Y-m-d H:i:s', $date + DAY_IN_SECONDS ), get_post_field( 'post_date_gmt', $id ), 'the host reschedule updates the stored date' );
		$t->same( $date + DAY_IN_SECONDS, wp_next_scheduled( 'publish_future_post', [ $id ] ), 'the publication event moves to the new date' );
		unset( $dates[ $guid ] );
		update_post_meta( $id, Episodes::META_PREFIX . 'missing_since', time() - 2 * DAY_IN_SECONDS );
		Hosting::sync( true );
		$t->same( 'draft', get_post_status( $id ), 'a removed future episode is drafted even beyond the visible feed window' );
		$t->same( false, wp_next_scheduled( 'publish_future_post', [ $id ] ), 'the removed episode cannot auto-publish' );
	} );

	$t->test( 'SYNC-N7: rate limits postpone the first scheduled retry and temporary failures do not blame the address', static function ( EPM_Test_Runner $t ) use ( $url ) {
		delete_option( Hosting::STATE_OPTION );
		EPM_Test_HTTP::$routes[ $url ] = static fn() => EPM_Test_HTTP::response( 429, '', [ 'retry-after' => '7200' ] );
		$before = time();
		$result = Hosting::sync( true );
		$t->same( 'error', $result['status'], 'rate limit is reported' );
		$t->assert( ( Hosting::state()['retry_at'] ?? 0 ) >= $before + 7200, 'Retry-After is honored on the first failure' );
		EPM_Test_HTTP::$log = [];
		Hosting::run_scheduled_sync();
		$t->same( [], EPM_Test_HTTP::$log, 'no requests before the host permits a retry' );
		EPM_Test_HTTP::$routes[ $url ] = static fn() => EPM_Test_HTTP::response( 503, '' );
		$error = Hosting::fetch( $url );
		$t->same( 'epm_feed_http_temporary', $error->get_error_code(), '503 is a temporary host failure' );
		$t->assert( false === stripos( $error->get_error_message(), 'Check the RSS address' ), 'the administrator is not told to change a correct address' );
	} );
} finally {
	foreach ( array_unique( $ids ) as $id ) {
		if ( $id > 0 ) { wp_delete_post( $id, true ); }
	}
	unset( EPM_Test_HTTP::$routes[ $url ] );
	foreach ( $saved as $name => $value ) {
		epm_test_option_restore( $name, $value );
	}
	wp_set_current_user( $user );
}
$t->finish();
