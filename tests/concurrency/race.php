<?php
/**
 * Two-process race scenarios for the import/sync lock and the import job.
 *
 *   wp eval-file tests/concurrency/race.php list
 *   wp eval-file tests/concurrency/race.php <scenario> setup|A|B|M|check
 *
 * run.sh runs setup, then the roles in parallel (each its own PHP
 * process), then check. Roles meet at barrier files (see lib.php). The
 * scenarios reproduce the interleavings of the IMP-02 / IMP-N2 audit:
 *
 * Lock (every one: exactly one holder, nobody overwrites or deletes a
 * fresh lock of another request)
 *   lock-fresh    both take a free lock; their writes run in lockstep
 *   lock-stale    both take over the same abandoned lock, in lockstep
 *   lock-refresh  A renews its (old-looking) lock while B takes it over
 *   lock-release  A releases its lock while B takes it over
 *   lock-loop     A (a loop like cron or `wp podcast import`) takes the
 *                 lock again while B holds it
 *
 * Job
 *   job-cron-step       cron's run_in_background() loop and a step() from
 *                       the import screen: no duplicate episodes, counts
 *                       match what was imported
 *   job-failed-over-done a step that read the job before A finished it
 *                       never marks the finished job failed
 *   job-failed-over-new a step that read an old job never overwrites the
 *                       next preview
 *   job-backwards       a step that read the job at position 0 never saves
 *                       an older position over A's progress
 *   job-preview-vs-start a preview that began before A started the import
 *                       never replaces the running import
 *   job-cancel-mid-batch Cancel while a batch runs: the episode in flight
 *                       may finish, nothing after it
 *   job-stress          (STRESS=1 only) cron loop plus a polling step loop
 *                       without barriers: no duplicates
 *
 * Episodes use GUIDs starting with "race-"/"second-"/"other-" and are
 * deleted by setup and check. Only for disposable test sites.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Hosting;
use EPM\ImportJob;

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to run race scenarios on a production site.' );
}

require_once __DIR__ . '/lib.php';
require_once dirname( __DIR__ ) . '/integration/lib.php';
if ( ! class_exists( 'EPM_Test_HTTP' ) ) {
	require dirname( __DIR__ ) . '/fixtures/mu-plugins/epm-test-http.php';
}

EPM_Test_HTTP::$offline = true;
// No WP-Cron spawned from these processes: the roles are the only workers.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		return false !== strpos( (string) $url, 'wp-cron.php' ) ? new WP_Error( 'epm_race', 'No cron during races.' ) : $pre;
	},
	1,
	3
);

define( 'EPM_RACE_FEED', 'https://feeds.example.test/race/feed.xml' );
define( 'EPM_RACE_SECOND', 'https://feeds.example.test/race/second.xml' );
define( 'EPM_RACE_OTHER', 'https://feeds.example.test/race/other.xml' );

/* ------------------------------------------------------------------------- */
/* Building blocks                                                           */
/* ------------------------------------------------------------------------- */

/**
 * The race feed (and the two other shows) in this process.
 *
 * @param int           $count  Items in the race feed.
 * @param callable|null $before Barrier before the "other" feed answers.
 * @return void
 */
function epm_race_routes( int $count, ?callable $before = null ): void {
	epm_race_route( EPM_RACE_FEED, epm_race_feed( 'Race show', 'race-', $count ) );
	epm_race_route( EPM_RACE_SECOND, epm_race_feed( 'Second show', 'second-', 1 ) );
	epm_race_route( EPM_RACE_OTHER, epm_race_feed( 'Other show', 'other-', 1 ), $before );
}

/**
 * Reset, then preview (and start) a mirror import of the race feed.
 *
 * @param int  $count Items.
 * @param bool $start Start the import.
 * @return void
 */
function epm_race_prepare_job( int $count, bool $start = true ): void {
	epm_race_reset( 'race-' );
	epm_race_reset( 'second-' );
	epm_race_reset( 'other-' );
	update_option( Hosting::OPTION, Hosting::sanitize( array_merge( Hosting::all(), [ 'mode' => 'self' ] ) ) );
	epm_race_routes( $count );
	$preview = ImportJob::preview( EPM_RACE_FEED );
	if ( is_wp_error( $preview ) ) {
		WP_CLI::error( 'Preview failed: ' . $preview->get_error_message() );
	}
	if ( $start ) {
		$state = ImportJob::start( (string) $preview['token'], [ 'purpose' => 'mirror' ] );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( 'Start failed: ' . $state->get_error_message() );
		}
	}
	wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
}

/**
 * Mark the running job as not stepped for two minutes, so cron takes over.
 *
 * @return void
 */
function epm_race_age_job(): void {
	global $wpdb;

	$job            = epm_race_job();
	$job['touched'] = time() - 120;
	$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $job ) ], [ 'option_name' => ImportJob::OPTION ] );
	wp_cache_delete( ImportJob::OPTION, 'options' );
}

/**
 * Make the lock row look abandoned (six minutes old), keeping its owner.
 *
 * @return void
 */
function epm_race_age_lock(): void {
	global $wpdb;

	$value = epm_race_lock_row();
	$colon = strpos( $value, ':' );
	$aged  = ( time() - 6 * MINUTE_IN_SECONDS ) . ( false === $colon ? '' : substr( $value, $colon ) );
	$wpdb->update( $wpdb->options, [ 'option_value' => $aged ], [ 'option_name' => 'epm_import_lock' ] );
	wp_cache_delete( 'epm_import_lock', 'options' );
	epm_race_log( 'aged the lock row to ' . $aged );
}

/**
 * Lockstep: the n-th write of each role to the lock row waits for the
 * n-th write of the other role (or its end), so both have read the row
 * before either writes.
 *
 * @return callable Turns the lockstep off.
 */
function epm_race_lockstep(): callable {
	$on   = true;
	$role = epm_race_role();
	epm_race_on_lock_statement(
		static function ( $verb, $count ) use ( &$on, $role ) {
			static $writes = 0;
			if ( ! $on || 'SELECT' === $verb ) {
				return;
			}
			++$writes;
			epm_race_signal( 'write' . $writes . '-' . $role );
			foreach ( epm_race_peers() as $peer ) {
				if ( 'M' !== $peer ) {
					epm_race_wait( 'write' . $writes . '-' . $peer, [ $peer ] );
				}
			}
		}
	);

	return static function () use ( &$on ) {
		$on = false;
	};
}

/**
 * Pause before the first statement that touches the lock row.
 *
 * @param string $signal Flag raised when paused.
 * @param string $wait   Flag that ends the pause.
 * @param string $verb   Only statements with this verb ('' for any).
 * @return void
 */
function epm_race_pause_at_lock( string $signal, string $wait, string $verb = '' ): void {
	epm_race_on_lock_statement(
		static function ( $statement ) use ( $signal, $wait, $verb ) {
			static $done = false;
			if ( $done || ( '' !== $verb && $verb !== $statement ) ) {
				return;
			}
			$done = true;
			epm_race_signal( $signal );
			epm_race_wait( $wait );
		}
	);
}

/**
 * Report a lock result: whether it was taken and the row right after.
 *
 * @param string $key      Result key.
 * @param bool   $acquired Whether acquire_lock() returned true.
 * @return void
 */
function epm_race_report_acquire( string $key, bool $acquired ): void {
	epm_race_result(
		[
			$key          => $acquired,
			$key . '_row' => $acquired ? epm_race_lock_row() : '',
		]
	);
}

/**
 * The owner part of a lock value.
 *
 * @param string $value Lock value.
 * @return string
 */
function epm_race_owner_of( string $value ): string {
	$colon = strpos( $value, ':' );

	return false === $colon ? '' : substr( $value, $colon + 1 );
}

/**
 * A short summary of a client state.
 *
 * @param array|WP_Error $state State.
 * @return array<string, mixed>
 */
function epm_race_state( $state ): array {
	if ( is_wp_error( $state ) ) {
		return [ 'error' => $state->get_error_code() . ': ' . $state->get_error_message() ];
	}

	return [
		'status' => (string) ( $state['status'] ?? '' ),
		'done'   => (int) ( $state['done'] ?? 0 ),
		'total'  => (int) ( $state['total'] ?? 0 ),
		'busy'   => ! empty( $state['busy'] ),
		'title'  => (string) ( $state['title'] ?? '' ),
	];
}

/**
 * Monitor: record every job position until the other roles finish.
 *
 * @return void
 */
function epm_race_monitor(): void {
	$seen = [];
	$last = '';
	$end  = microtime( true ) + 120;
	while ( ! epm_race_peers_done() && microtime( true ) < $end ) {
		$job = epm_race_job();
		$key = substr( (string) ( $job['token'] ?? '' ), 0, 8 ) . '|' . (int) ( $job['position'] ?? 0 ) . '|' . (string) ( $job['status'] ?? '' );
		if ( $key !== $last ) {
			$seen[] = $key;
			$last   = $key;
		}
		usleep( 5000 );
	}
	epm_race_result( [ 'positions' => $seen ] );
}

/**
 * Check helpers shared by the job scenarios: no duplicate episodes,
 * positions that never go back, counts that match the episodes.
 *
 * @param EPM_Test_Runner $t       Runner.
 * @param string          $prefix  GUID prefix.
 * @param bool            $monitor Whether a monitor ran.
 * @return array<string, int> Episodes per GUID.
 */
function epm_race_check_episodes( EPM_Test_Runner $t, string $prefix = 'race-', bool $monitor = false ): array {
	$counts = epm_race_guid_counts( $prefix );
	$dupes  = array_filter(
		$counts,
		static function ( $n ) {
			return $n > 1;
		}
	);
	$t->same( [], $dupes, 'no GUID imported twice' );

	$job = epm_race_job();
	if ( ! empty( $job['counts'] ) ) {
		$sum = 0;
		foreach ( [ 'created', 'updated', 'unchanged', 'skipped', 'failed' ] as $key ) {
			$sum += (int) ( $job['counts'][ $key ] ?? 0 );
		}
		$t->same( (int) ( $job['position'] ?? 0 ), $sum, 'outcomes counted once per position' );
		$t->same( count( $counts ), (int) ( $job['counts']['created'] ?? 0 ), '"new" equals the episodes created' );
	}

	if ( $monitor ) {
		$positions = epm_race_results( 'M' )['positions'] ?? [];
		$by_token  = [];
		$backwards = [];
		foreach ( $positions as $entry ) {
			[ $token, $position ] = explode( '|', $entry );
			if ( isset( $by_token[ $token ] ) && (int) $position < $by_token[ $token ] ) {
				$backwards[] = $by_token[ $token ] . ' -> ' . $position;
			}
			$by_token[ $token ] = max( (int) $position, $by_token[ $token ] ?? 0 );
		}
		$t->same( [], $backwards, 'the position never goes back' );
	}

	return $counts;
}

/**
 * Barriers that timed out (a hung interleaving).
 *
 * @param EPM_Test_Runner $t     Runner.
 * @param string[]        $roles Roles.
 * @return void
 */
function epm_race_check_timeouts( EPM_Test_Runner $t, array $roles ): void {
	$timeouts = [];
	foreach ( $roles as $role ) {
		foreach ( (array) ( epm_race_results( $role )['timeouts'] ?? [] ) as $name ) {
			$timeouts[] = $role . ': ' . $name;
		}
		if ( ! empty( epm_race_results( $role )['exception'] ) ) {
			$t->assert( false, $role . ' threw ' . epm_race_results( $role )['exception'] );
		}
	}
	$t->same( [], $timeouts, 'no barrier timed out' );
}

/* ------------------------------------------------------------------------- */
/* Scenarios                                                                 */
/* ------------------------------------------------------------------------- */

/**
 * Lock check: exactly one holder at the end, and the row is its lock.
 *
 * @param EPM_Test_Runner $t      Runner.
 * @param string          $holder Role expected to hold the lock ('' = either).
 * @return void
 */
function epm_race_check_one_holder( EPM_Test_Runner $t, string $holder = '' ): void {
	$results = [
		'A' => epm_race_results( 'A' ),
		'B' => epm_race_results( 'B' ),
	];
	$held    = [];
	foreach ( $results as $role => $result ) {
		if ( ! empty( $result['holds_at_end'] ) ) {
			$held[] = $role;
		}
	}
	$t->same( 1, count( $held ), 'exactly one request holds the lock at the end (holders: ' . implode( ', ', $held ) . ')' );
	if ( '' !== $holder ) {
		$t->same( [ $holder ], $held, 'the lock belongs to ' . $holder );
	}
	if ( 1 === count( $held ) ) {
		$t->same( (string) ( $results[ $held[0] ]['owner'] ?? '' ), epm_race_owner_of( epm_race_lock_row() ), 'the lock row is the holder\'s lock' );
	}
}

/**
 * Record whether this role still holds the lock (renewing it proves it).
 *
 * @return void
 */
function epm_race_report_holds(): void {
	$holds = ImportJob::refresh_lock();
	epm_race_result(
		[
			'holds_at_end' => $holds,
			'owner'        => $holds ? epm_race_owner_of( epm_race_lock_row() ) : '',
		]
	);
}

$epm_race_scenarios = [];

$epm_race_scenarios['lock-fresh'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_reset();
	},
	'role'  => static function () {
		$off      = epm_race_lockstep();
		$acquired = ImportJob::acquire_lock();
		$off();
		epm_race_report_acquire( 'acquired', $acquired );
		epm_race_signal( 'acquired-' . epm_race_role() );
		foreach ( epm_race_peers() as $peer ) {
			epm_race_wait( 'acquired-' . $peer );
		}
		epm_race_report_holds();
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$a = epm_race_results( 'A' );
		$b = epm_race_results( 'B' );
		$t->same( 1, (int) ! empty( $a['acquired'] ) + (int) ! empty( $b['acquired'] ), 'exactly one of two simultaneous requests takes a free lock' );
		epm_race_check_one_holder( $t );
	},
];

$epm_race_scenarios['lock-stale'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		global $wpdb;
		epm_race_reset();
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => 'epm_import_lock',
				'option_value' => ( time() - 10 * MINUTE_IN_SECONDS ) . ':crashed-request',
				'autoload'     => 'no',
			]
		);
	},
	'role'  => $epm_race_scenarios['lock-fresh']['role'],
	'check' => static function ( EPM_Test_Runner $t ) {
		$a = epm_race_results( 'A' );
		$b = epm_race_results( 'B' );
		$t->same( 1, (int) ! empty( $a['acquired'] ) + (int) ! empty( $b['acquired'] ), 'exactly one of two requests takes over an abandoned lock' );
		epm_race_check_one_holder( $t );
	},
];

$epm_race_scenarios['lock-refresh'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_reset();
	},
	'A'     => static function () {
		$acquired = ImportJob::acquire_lock();
		epm_race_report_acquire( 'first', $acquired );
		$owner = epm_race_owner_of( epm_race_lock_row() );
		// One slow episode later: the lock looks abandoned to others.
		epm_race_age_lock();
		epm_race_pause_at_lock( 'A-refreshing', 'B-acquired', 'UPDATE' );
		epm_race_signal( 'A-ready' );
		// A second later, so the renewal has a new time to write.
		sleep( 1 );
		$refreshed = ImportJob::refresh_lock();
		epm_race_result( [ 'refresh_returned' => $refreshed ] );
		// (A renewal that writes nothing never reached the barrier.)
		epm_race_signal( 'A-refreshing' );
		epm_race_signal( 'A-refreshed' );
		epm_race_wait( 'B-checked' );
		epm_race_result(
			[
				'holds_at_end' => $refreshed && epm_race_owner_of( epm_race_lock_row() ) === $owner,
				'owner'        => $owner,
			]
		);
	},
	'B'     => static function () {
		epm_race_wait( 'A-ready' );
		// A is renewing its lock: it checked the row and is about to write.
		epm_race_wait( 'A-refreshing' );
		$acquired = ImportJob::acquire_lock();
		epm_race_report_acquire( 'acquired', $acquired );
		epm_race_signal( 'B-acquired' );
		epm_race_wait( 'A-refreshed' );
		epm_race_report_holds();
		epm_race_signal( 'B-checked' );
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$a = epm_race_results( 'A' );
		$b = epm_race_results( 'B' );
		$t->assert( ! ( ! empty( $a['refresh_returned'] ) && ! empty( $b['acquired'] ) ), 'a renewal and a takeover never both succeed' );
		epm_race_check_one_holder( $t );
	},
];

$epm_race_scenarios['lock-release'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_reset();
	},
	'A'     => static function () {
		$acquired = ImportJob::acquire_lock();
		epm_race_report_acquire( 'first', $acquired );
		epm_race_age_lock();
		epm_race_pause_at_lock( 'A-releasing', 'B-acquired', 'DELETE' );
		epm_race_signal( 'A-ready' );
		ImportJob::release_lock();
		epm_race_result( [ 'holds_at_end' => false ] );
		epm_race_signal( 'A-released' );
	},
	'B'     => static function () {
		epm_race_wait( 'A-ready' );
		epm_race_wait( 'A-releasing' );
		$acquired = ImportJob::acquire_lock();
		epm_race_report_acquire( 'acquired', $acquired );
		epm_race_signal( 'B-acquired' );
		epm_race_wait( 'A-released' );
		epm_race_report_holds();
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$b = epm_race_results( 'B' );
		$t->assert( ! empty( $b['acquired'] ), 'B takes over the abandoned lock' );
		$t->assert( ! empty( $b['holds_at_end'] ), 'A\'s release does not delete B\'s lock' );
		epm_race_check_one_holder( $t, 'B' );
	},
];

$epm_race_scenarios['lock-loop'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_reset();
	},
	'A'     => static function () {
		epm_race_report_acquire( 'first', ImportJob::acquire_lock() );
		ImportJob::release_lock();
		epm_race_signal( 'A-released' );
		epm_race_wait( 'B-holding' );
		// The next round of the loop, in the same process.
		$again = ImportJob::acquire_lock();
		epm_race_report_acquire( 'second', $again );
		epm_race_signal( 'A-second' );
		epm_race_wait( 'B-checked' );
		if ( $again ) {
			epm_race_report_holds();
		}
	},
	'B'     => static function () {
		epm_race_wait( 'A-released' );
		$acquired = ImportJob::acquire_lock();
		epm_race_report_acquire( 'acquired', $acquired );
		epm_race_signal( 'B-holding' );
		epm_race_wait( 'A-second' );
		epm_race_report_holds();
		epm_race_signal( 'B-checked' );
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$a = epm_race_results( 'A' );
		$b = epm_race_results( 'B' );
		$t->assert( ! empty( $b['acquired'] ), 'B takes the lock A released' );
		$t->same( false, (bool) ( $a['second'] ?? null ), 'A\'s next round does not take B\'s lock' );
		epm_race_check_one_holder( $t, 'B' );
	},
];

$epm_race_scenarios['job-cron-step'] = [
	'roles' => [ 'A', 'B', 'M' ],
	'setup' => static function () {
		epm_race_prepare_job( 30 );
		epm_race_age_job();
	},
	'A'     => static function () {
		epm_race_routes( 30 );
		// After its first batch released the lock, cron's loop waits until
		// the import screen's step holds it and imports.
		$released = false;
		epm_race_on_lock_statement(
			static function ( $verb ) use ( &$released ) {
				static $done = false;
				if ( 'DELETE' === $verb ) {
					$released = true;
					return;
				}
				if ( $released && ! $done ) {
					$done = true;
					epm_race_signal( 'A-released-1' );
					epm_race_wait( 'B-holding' );
				}
			}
		);
		add_filter(
			'wp_insert_post_data',
			static function ( $data ) use ( &$released ) {
				static $done = false;
				if ( $released && ! $done && 'podcast_episode' === ( $data['post_type'] ?? '' ) ) {
					$done = true;
					epm_race_signal( 'A-pre' );
				}
				return $data;
			}
		);
		ImportJob::run_in_background();
		epm_race_result( [ 'loop' => epm_race_state( ImportJob::client_state( epm_race_job() ) ) ] );
	},
	'B'     => static function () {
		epm_race_routes( 30 );
		epm_race_wait( 'A-released-1' );
		add_filter(
			'wp_insert_post_data',
			static function ( $data ) {
				static $done = false;
				if ( ! $done && 'podcast_episode' === ( $data['post_type'] ?? '' ) ) {
					$done = true;
					epm_race_signal( 'B-holding' );
					epm_race_wait( 'A-pre' );
				}
				return $data;
			}
		);
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
	},
	'M'     => 'epm_race_monitor',
	'check' => static function ( EPM_Test_Runner $t ) {
		$counts = epm_race_check_episodes( $t, 'race-', true );
		$t->assert( count( $counts ) >= 10, 'episodes were imported (' . count( $counts ) . ')' );
	},
];

$epm_race_scenarios['job-failed-over-done'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_prepare_job( 10 );
	},
	'A'     => static function () {
		epm_race_routes( 10 );
		epm_race_wait( 'B-paused' );
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
		epm_race_signal( 'A-done' );
	},
	'B'     => static function () {
		epm_race_routes( 10 );
		// B read the job, then waits at the lock until A finished it.
		epm_race_pause_at_lock( 'B-paused', 'A-done' );
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$job = epm_race_job();
		$t->same( 'done', (string) ( $job['status'] ?? '' ), 'the finished import stays finished, not "failed"' );
		$t->same( '', (string) ( $job['error'] ?? '' ), 'no error recorded' );
		$counts = epm_race_check_episodes( $t );
		$t->same( 10, count( $counts ), 'all ten episodes' );
	},
];

$epm_race_scenarios['job-failed-over-new'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_prepare_job( 10 );
	},
	'A'     => static function () {
		epm_race_routes( 10 );
		epm_race_wait( 'B-paused' );
		ImportJob::cancel();
		// The admin checks another feed right away.
		$preview = ImportJob::preview( EPM_RACE_SECOND );
		epm_race_result( [ 'preview' => is_wp_error( $preview ) ? $preview->get_error_message() : (string) $preview['token'] ] );
		epm_race_signal( 'A-new' );
		epm_race_wait( 'B-end' );
		// "Import" is clicked: start() runs in a request of its own.
		wp_cache_delete( ImportJob::OPTION, 'options' );
		$state = is_wp_error( $preview ) ? $preview : ImportJob::start( (string) $preview['token'], [ 'purpose' => 'mirror' ] );
		epm_race_result( [ 'start' => epm_race_state( $state ) ] );
	},
	'B'     => static function () {
		epm_race_routes( 10 );
		epm_race_pause_at_lock( 'B-paused', 'A-new' );
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
		epm_race_signal( 'B-end' );
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$a   = epm_race_results( 'A' );
		$job = epm_race_job();
		$t->same( 'running', $a['start']['status'] ?? ( $a['start']['error'] ?? '' ), 'the new import starts' );
		$t->same( (string) ( $a['preview'] ?? '' ), (string) ( $job['token'] ?? '' ), 'the job is the new preview' );
		$t->same( 'Second show', (string) ( $job['channel']['title'] ?? '' ) );
		epm_race_reset( 'second-' );
	},
];

$epm_race_scenarios['job-backwards'] = [
	'roles' => [ 'A', 'B', 'M' ],
	'setup' => static function () {
		epm_race_prepare_job( 40 );
	},
	'A'     => static function () {
		epm_race_routes( 40 );
		epm_race_wait( 'B-paused' );
		ImportJob::step( 8.0 );
		wp_cache_delete( ImportJob::OPTION, 'options' );
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
		epm_race_signal( 'A-two' );
	},
	'B'     => static function () {
		epm_race_routes( 40 );
		epm_race_pause_at_lock( 'B-paused', 'A-two' );
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
	},
	'M'     => 'epm_race_monitor',
	'check' => static function ( EPM_Test_Runner $t ) {
		$a   = epm_race_results( 'A' );
		$job = epm_race_job();
		$t->same( 20, (int) ( $a['step']['done'] ?? 0 ), 'A imported two batches' );
		$t->assert( (int) ( $job['position'] ?? 0 ) >= 20, 'the saved position is not older than A\'s (' . (int) ( $job['position'] ?? 0 ) . ')' );
		epm_race_check_episodes( $t, 'race-', true );
	},
];

$epm_race_scenarios['job-preview-vs-start'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_prepare_job( 30, false );
	},
	'A'     => static function () {
		epm_race_routes( 30 );
		epm_race_wait( 'B-fetching' );
		$token = (string) ( epm_race_job()['token'] ?? '' );
		$state = ImportJob::start( $token, [ 'purpose' => 'mirror' ] );
		epm_race_result( [ 'start' => epm_race_state( $state ) ] );
		epm_race_result( [ 'step1' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
		epm_race_signal( 'A-started' );
		epm_race_wait( 'B-end' );
		wp_cache_delete( ImportJob::OPTION, 'options' );
		epm_race_result( [ 'step2' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
	},
	'B'     => static function () {
		// B checks another feed; the host answers slowly, and meanwhile A
		// starts the import it had checked before.
		epm_race_routes(
			30,
			static function () {
				epm_race_signal( 'B-fetching' );
				epm_race_wait( 'A-started' );
			}
		);
		$preview = ImportJob::preview( EPM_RACE_OTHER );
		epm_race_result( [ 'preview' => is_wp_error( $preview ) ? 'error: ' . $preview->get_error_code() : 'ok ' . (int) $preview['episodes'] ] );
		epm_race_signal( 'B-end' );
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$a   = epm_race_results( 'A' );
		$b   = epm_race_results( 'B' );
		$job = epm_race_job();
		$t->same( 'running', $a['start']['status'] ?? '', 'A started its import' );
		$t->assert( 0 === strpos( (string) ( $b['preview'] ?? '' ), 'error' ), 'the late preview is refused (' . ( $b['preview'] ?? '' ) . ')' );
		$t->same( 'Race show', (string) ( $job['channel']['title'] ?? '' ), 'the running import is still the job' );
		$t->same( 20, (int) ( $a['step2']['done'] ?? 0 ), 'and continues where it was' );
		epm_race_check_episodes( $t );
		epm_race_reset( 'other-' );
	},
];

$epm_race_scenarios['job-cancel-mid-batch'] = [
	'roles' => [ 'A', 'B' ],
	'setup' => static function () {
		epm_race_prepare_job( 10 );
	},
	'A'     => static function () {
		epm_race_routes( 10 );
		$created = 0;
		add_action(
			'wp_insert_post',
			static function ( $id, $post ) use ( &$created ) {
				if ( 'podcast_episode' === $post->post_type && 3 === ++$created ) {
					// "Stop the import" while the third episode is imported.
					epm_race_signal( 'A-third' );
					epm_race_wait( 'B-cancelled' );
				}
			},
			10,
			2
		);
		epm_race_result( [ 'step' => epm_race_state( ImportJob::step( 8.0 ) ) ] );
		epm_race_result( [ 'created' => $created ] );
	},
	'B'     => static function () {
		epm_race_wait( 'A-third' );
		epm_race_result( [ 'cancel' => epm_race_state( ImportJob::cancel() ) ] );
		epm_race_signal( 'B-cancelled' );
	},
	'check' => static function ( EPM_Test_Runner $t ) {
		$job    = epm_race_job();
		$counts = epm_race_guid_counts( 'race-' );
		$t->same( 'cancelled', (string) ( $job['status'] ?? '' ), 'the import stays cancelled' );
		$t->assert( count( $counts ) <= 3, 'only the episode in flight finishes after Cancel (' . count( $counts ) . ' created)' );
		$t->same( count( $counts ), (int) ( $job['position'] ?? -1 ), 'the progress matches the episodes created' );
		$t->same( count( $counts ), (int) ( $job['counts']['created'] ?? -1 ), '"new" matches the episodes created' );
	},
];

$epm_race_scenarios['job-stress'] = [
	'stress' => true,
	'roles'  => [ 'A', 'B', 'M' ],
	'setup'  => static function () {
		epm_race_prepare_job( (int) ( getenv( 'STRESS_ITEMS' ) ?: 200 ) );
		epm_race_age_job();
	},
	'A'      => static function () {
		epm_race_routes( (int) ( getenv( 'STRESS_ITEMS' ) ?: 200 ) );
		$end = microtime( true ) + 120;
		while ( 'running' === ( epm_race_job()['status'] ?? '' ) && microtime( true ) < $end ) {
			epm_race_age_job();
			ImportJob::run_in_background();
			usleep( 50000 );
		}
	},
	'B'      => static function () {
		epm_race_routes( (int) ( getenv( 'STRESS_ITEMS' ) ?: 200 ) );
		$end = microtime( true ) + 120;
		do {
			wp_cache_delete( ImportJob::OPTION, 'options' );
			$state = ImportJob::step( 8.0 );
			usleep( ! empty( $state['busy'] ) ? 500000 : 150000 );
		} while ( 'running' === $state['status'] && microtime( true ) < $end );
	},
	'M'      => 'epm_race_monitor',
	'check'  => static function ( EPM_Test_Runner $t ) {
		$job = epm_race_job();
		$t->same( 'done', (string) ( $job['status'] ?? '' ), 'the import finishes' );
		$counts = epm_race_check_episodes( $t, 'race-', true );
		$t->same( (int) ( getenv( 'STRESS_ITEMS' ) ?: 200 ), count( $counts ), 'every episode once' );
	},
];

/* ------------------------------------------------------------------------- */
/* Dispatch                                                                  */
/* ------------------------------------------------------------------------- */

$epm_race_name  = (string) ( $args[0] ?? '' );
$epm_race_phase = (string) ( $args[1] ?? '' );

if ( 'list' === $epm_race_name ) {
	foreach ( $epm_race_scenarios as $name => $scenario ) {
		echo $name . ' ' . implode( ',', $scenario['roles'] ) . ( ! empty( $scenario['stress'] ) ? ' stress' : '' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}

if ( ! isset( $epm_race_scenarios[ $epm_race_name ] ) ) {
	WP_CLI::error( 'Unknown scenario: ' . $epm_race_name );
}
$epm_race = $epm_race_scenarios[ $epm_race_name ];

switch ( $epm_race_phase ) {
	case 'setup':
		$epm_race['setup']();
		break;

	case 'check':
		$t = new EPM_Test_Runner();
		$t->test(
			$epm_race_name,
			static function ( EPM_Test_Runner $t ) use ( $epm_race ) {
				epm_race_check_timeouts( $t, $epm_race['roles'] );
				$epm_race['check']( $t );
			}
		);
		epm_race_reset( 'race-' );
		$t->finish();
		break;

	default:
		$epm_race_fn = $epm_race[ $epm_race_phase ] ?? $epm_race['role'] ?? null;
		if ( ! is_callable( $epm_race_fn ) ) {
			WP_CLI::error( 'Unknown role: ' . $epm_race_phase );
		}
		try {
			$epm_race_fn();
		} catch ( Throwable $e ) {
			epm_race_result( [ 'exception' => get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() ] );
		} finally {
			epm_race_signal( 'done-' . epm_race_role() );
		}
}
