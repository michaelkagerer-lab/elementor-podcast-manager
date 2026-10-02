<?php
/**
 * Batched feed import (Layer 1).
 *
 * A show with hundreds of episodes cannot be imported in one request, so
 * the import runs as a job:
 *
 * 1. preview: read the feed page by page (a paged feed over several
 *    requests), store the parsed items in the database (ImportStore) and
 *    report what was found and whether the catalog is complete.
 * 2. start:   apply the show details (optional) and mark the job running.
 * 3. step:    import a few items per request (AJAX from the import screen).
 *    When the browser is closed, a WP-Cron event keeps the job going.
 *    With "copy media", a file that does not arrive within one request is
 *    continued by the next one (MediaDownload); a host that asks to wait
 *    (HTTP 429) puts the job in the state "waiting" until then.
 * 4. end:     "done", or for a move that left files at the old host (or
 *    episodes that failed) "done_with_problems": the move is not finished
 *    until a retry copies them (retry()) or the site owner confirms that
 *    they stay behind (confirm_move()).
 *
 * Only one import or sync runs at a time (a lock row changed only with
 * conditional statements). The job is one option, read from the database
 * and saved with compare-and-swap, so requests never overwrite each
 * other's changes.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportJob {

	public const OPTION = 'epm_import_job';

	public const CRON_HOOK = 'epm_import_continue';

	/**
	 * Cron hook: expire checked feeds nobody imported, remove stored data
	 * no job uses (see cleanup()).
	 */
	public const CLEANUP_HOOK = 'epm_import_cleanup';

	private const LOCK = 'epm_import_lock';

	private const NONCE = 'epm_import';

	/**
	 * Owner part of the lock this request holds ('' when it holds none).
	 * The lock's value is "<time>:<owner>".
	 *
	 * @var string
	 */
	private static string $lock_owner = '';

	/**
	 * The lock value this request last wrote ('' when it holds none).
	 *
	 * @var string
	 */
	private static string $lock_value = '';

	/**
	 * When this request last wrote its lock.
	 *
	 * @var int
	 */
	private static int $lock_written = 0;

	/**
	 * Seconds between two renewals of a held lock (keep_lock()).
	 */
	private const LOCK_RENEW = 10;

	/**
	 * GUID hashes of the stored items of the job this request reads
	 * (store, segments, hashes), see seen().
	 *
	 * @var array<string, mixed>
	 */
	private static array $seen = [];

	/**
	 * Wire AJAX handlers and the background continuation.
	 *
	 * @return void
	 */
	public function init(): void {
		foreach ( [ 'preview', 'more', 'start', 'step', 'cancel', 'status', 'retry', 'confirm' ] as $action ) {
			add_action( 'wp_ajax_epm_import_' . $action, [ $this, 'ajax_' . $action ] );
		}
		add_action( 'wp_ajax_epm_sync_now', [ $this, 'ajax_sync_now' ] );
		add_action( self::CRON_HOOK, [ self::class, 'run_in_background' ] );
		add_action( self::CLEANUP_HOOK, [ self::class, 'cleanup' ] );
		add_action( 'admin_init', [ self::class, 'maybe_cleanup' ] );
	}

	/**
	 * Nonce for the import screens.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return wp_create_nonce( self::NONCE );
	}

	/**
	 * Take the import/sync lock. Stale locks (a crashed request) expire.
	 *
	 * The lock is a row in the options table, changed only with conditional
	 * statements, so two requests can never both hold it: a free lock is
	 * taken by inserting the row only when it is missing, an abandoned one
	 * by replacing exactly the value that was read. Option caches are
	 * bypassed (a persistent object cache or the "not an option" cache of
	 * a long-running process would otherwise let a request overwrite a
	 * live lock). The lock stores its time and an owner, so only the
	 * request that took it renews or releases it.
	 *
	 * @return bool
	 */
	public static function acquire_lock(): bool {
		$owner = wp_generate_password( 12, false );
		$value = time() . ':' . $owner;

		if ( self::lock_insert( $value ) ) {
			return self::hold_lock( $owner, $value );
		}

		$current = self::lock_read();
		if ( null === $current ) {
			// Released in the meantime.
			return self::lock_insert( $value ) && self::hold_lock( $owner, $value );
		}

		$since = (int) strtok( $current, ':' );
		if ( $since > 0 && time() - $since <= self::lock_ttl() ) {
			return false;
		}

		// Abandoned: take it over, unless another request just did.
		return self::lock_replace( $current, $value ) && self::hold_lock( $owner, $value );
	}

	/**
	 * Remember the lock this request now holds.
	 *
	 * @param string $owner Owner.
	 * @param string $value Lock value.
	 * @return bool True.
	 */
	private static function hold_lock( string $owner, string $value ): bool {
		self::$lock_owner   = $owner;
		self::$lock_value   = $value;
		self::$lock_written = time();

		return true;
	}

	/**
	 * An abandoned request becomes recoverable after five minutes. Media
	 * requests run for at most 50 seconds, leaving time to store a file;
	 * active multi-step workers renew the lock between pieces of work.
	 */
	private static function lock_ttl(): int {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether this request holds the lock (read from the database).
	 *
	 * @return bool
	 */
	private static function owns_lock(): bool {
		return '' !== self::$lock_value && self::lock_read() === self::$lock_value;
	}

	/**
	 * Renew the lock this request holds, so a long import is never taken
	 * for a crashed one.
	 *
	 * @return bool Whether the lock is still held by this request.
	 */
	public static function refresh_lock(): bool {
		if ( '' === self::$lock_value ) {
			return false;
		}

		$value = time() . ':' . self::$lock_owner;
		if ( $value === self::$lock_value ? self::owns_lock() : self::lock_replace( self::$lock_value, $value ) ) {
			self::$lock_value   = $value;
			self::$lock_written = time();
			return true;
		}

		// Taken over: this request holds nothing any more.
		self::$lock_owner = '';
		self::$lock_value = '';

		return false;
	}

	/**
	 * Check the lock before the next piece of work: renewed every few
	 * seconds, otherwise only verified.
	 *
	 * @return bool Whether this request still holds the lock.
	 */
	public static function keep_lock(): bool {
		if ( '' === self::$lock_value ) {
			return false;
		}

		if ( time() - self::$lock_written >= self::LOCK_RENEW ) {
			return self::refresh_lock();
		}

		if ( self::owns_lock() ) {
			return true;
		}

		self::$lock_owner = '';
		self::$lock_value = '';

		return false;
	}

	/**
	 * Release the lock, if this request holds it (a lock taken over by
	 * another request stays).
	 *
	 * @return void
	 */
	public static function release_lock(): void {
		if ( '' !== self::$lock_value ) {
			self::lock_delete( self::$lock_value );
		}
		self::$lock_owner = '';
		self::$lock_value = '';
	}

	/**
	 * The lock row's value, read from the database (null when there is none).
	 *
	 * @return string|null
	 */
	private static function lock_read(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock must never be read from a cache.

		return null === $value ? null : (string) $value;
	}

	/**
	 * Insert the lock row only when it does not exist.
	 *
	 * @param string $value Lock value.
	 * @return bool Whether this request inserted it.
	 */
	private static function lock_insert( string $value ): bool {
		global $wpdb;

		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::LOCK, $value, self::no_autoload() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic test-and-set.
		self::forget_option( self::LOCK );

		return 1 === (int) $rows;
	}

	/**
	 * Replace the lock row's value only while it is $expected.
	 *
	 * @param string $expected Value read before.
	 * @param string $value    New value.
	 * @return bool Whether the row was replaced.
	 */
	private static function lock_replace( string $expected, string $value ): bool {
		global $wpdb;

		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $value, self::LOCK, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap.
		self::forget_option( self::LOCK );

		return 1 === (int) $rows;
	}

	/**
	 * Delete the lock row only while it is $expected.
	 *
	 * @param string $expected Value this request wrote.
	 * @return void
	 */
	private static function lock_delete( string $expected ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", self::LOCK, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-delete.
		self::forget_option( self::LOCK );
	}

	/**
	 * Drop an option from every options cache after a direct write, so the
	 * next get_option() reads the database.
	 *
	 * @param string $name Option.
	 * @return void
	 */
	private static function forget_option( string $name ): void {
		wp_cache_delete( $name, 'options' );

		foreach ( [ 'notoptions', 'alloptions' ] as $key ) {
			$cached = wp_cache_get( $key, 'options' );
			if ( is_array( $cached ) && array_key_exists( $name, $cached ) ) {
				unset( $cached[ $name ] );
				wp_cache_set( $key, $cached, 'options' );
			}
		}
	}

	/**
	 * The "not autoloaded" value of the options table's autoload column.
	 *
	 * @return string
	 */
	private static function no_autoload(): string {
		// WordPress 6.6 writes "off"; earlier versions only know "no".
		return function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';
	}

	/**
	 * Current job (empty array when none), read from the database: every
	 * decision about the job is made on what other requests last saved,
	 * never on a cached copy.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return self::job_read()['job'];
	}

	/**
	 * The job and the stored value it was read from (null when none).
	 *
	 * @return array{job: array<string, mixed>, raw: string|null}
	 */
	private static function job_read(): array {
		global $wpdb;

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- never from a cache, see get().
		$job = null === $raw ? [] : maybe_unserialize( (string) $raw );

		return [
			'job' => is_array( $job ) ? $job : [],
			'raw' => null === $raw ? null : (string) $raw,
		];
	}

	/**
	 * Save the job only if it is still stored as $expected (compare-and-
	 * swap). Every save bumps the job's version, so two different states
	 * never compare equal.
	 *
	 * @param string|null          $expected Stored value read before (null: no job stored).
	 * @param array<string, mixed> $job      Job to save.
	 * @return array<string, mixed>|null The saved job, null when another request changed it first.
	 */
	private static function job_swap( ?string $expected, array $job ): ?array {
		global $wpdb;

		$job['version'] = (int) ( $job['version'] ?? 0 ) + 1;
		$value          = maybe_serialize( $job );

		if ( null === $expected ) {
			$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::OPTION, $value, self::no_autoload() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap.
		} else {
			$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $value, self::OPTION, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap.
		}
		self::forget_option( self::OPTION );

		return 1 === (int) $rows ? $job : null;
	}

	/**
	 * Change the job: $change gets the current job and returns the new one
	 * (or null to leave it alone). Retried on the current job when another
	 * request saved in between, so no change is ever made to an old copy.
	 *
	 * @param callable $change Receives the job (array), returns array|null.
	 * @return array<string, mixed>|null The saved job, or null when $change declined.
	 */
	private static function job_update( callable $change ): ?array {
		for ( $try = 0; $try < 20; $try++ ) {
			$read = self::job_read();
			$job  = $change( $read['job'] );
			if ( ! is_array( $job ) ) {
				return null;
			}
			$saved = self::job_swap( $read['raw'], $job );
			if ( null !== $saved ) {
				return $saved;
			}
		}

		return null;
	}

	/**
	 * Seconds a preview request spends reading pages before it hands over
	 * to the next request (filter epm_import_request_seconds).
	 *
	 * @return float
	 */
	public static function request_seconds(): float {
		return max( 1.0, (float) apply_filters( 'epm_import_request_seconds', 10.0 ) );
	}

	/**
	 * Check a feed: read its first pages and store what was found.
	 *
	 * Paged feeds (atom:link rel="next", SoundCloud serves 500 items per
	 * page) are read page by page, within a time and memory budget per
	 * request; when the budget is used up the summary says "loading" and
	 * preview_more() continues. The catalog ends with a reason: complete,
	 * or why it stopped (http_error, transport_error, parse_error,
	 * empty_page_with_next, page_limit, budget), with the error and the
	 * page address, so the missing part can be read again later.
	 *
	 * @param string $url    Feed URL, Apple Podcasts link or web page.
	 * @param float  $budget Seconds to spend reading pages (0: default).
	 * @return array<string, mixed>|\WP_Error Summary for the UI.
	 */
	public static function preview( string $url, float $budget = 0.0 ) {
		$deadline = microtime( true ) + ( $budget > 0 ? $budget : self::request_seconds() );

		if ( self::is_active( self::get() ) ) {
			return self::running_error();
		}

		// Leftovers: data of no current job, files 1.3.0 kept in uploads.
		self::sweep();
		self::cleanup_legacy_folder();

		$located = Hosting::locate( $url );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		$fetched = $located['fetched'];

		$parsed = ( new FeedParser() )->parse( $fetched['body'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$feed_url = (string) $located['url'];
		$provider = Providers::detect( $feed_url );
		if ( null === $provider ) {
			$provider = Providers::detect( Hosting::sanitize_feed_url( $url ) );
		}
		if ( null === $provider ) {
			$provider = Providers::detect_generator( (string) $parsed['channel']['generator'] );
		}

		$job = [
			'token'    => wp_generate_password( 24, false ),
			'user'     => get_current_user_id(),
			'status'   => 'loading',
			'feed_url' => $feed_url,
			'provider' => $provider,
			'channel'  => $parsed['channel'],
			'store'    => ImportStore::new_key(),
			'catalog'  => [
				'loaded'   => 0,
				'pages'    => [],
				'next'     => $feed_url,
				'bytes'    => 0,
				'segments' => [],
				'sorted'   => [],
				'items'    => 0,
				'complete' => false,
				'reason'   => '',
				'error'    => '',
				'url'      => '',
			],
			'stats'    => [
				'existing'        => 0,
				'with_audio'      => 0,
				'tracked'         => 0,
				'blocked'         => 0,
				'newest'          => 0,
				'oldest'          => 0,
				'duplicates'      => [],
				'duplicate_count' => 0,
			],
			'total'    => 0,
			'position' => 0,
			'counts'   => self::empty_counts(),
			'log'      => [],
			'created'  => time(),
			'touched'  => time(),
		];

		// Replace the previous job, unless an import started meanwhile (in
		// another tab, the setup assistant or WP-CLI): a preview never
		// replaces a running import.
		$replaced = [];
		$saved    = self::job_update(
			static function ( array $current ) use ( $job, &$replaced ) {
				if ( self::is_active( $current ) ) {
					return null;
				}
				$replaced       = $current;
				$job['version'] = (int) ( $current['version'] ?? 0 );
				return $job;
			}
		);
		if ( null === $saved ) {
			return self::running_error();
		}
		self::discard( $replaced );
		self::schedule_cleanup( (int) $saved['created'] );

		$recorded = self::record_page( $saved, $feed_url, (string) $fetched['final_url'], $parsed, strlen( (string) $fetched['body'] ) );
		unset( $fetched, $located, $parsed );
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		$loaded = self::load( (string) $saved['token'], $deadline, true );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		return self::own_summary( (string) $saved['token'] );
	}

	/**
	 * Summary of the job with this token (an error when another preview
	 * replaced it meanwhile, never the other job's summary).
	 *
	 * @param string $token Job token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function own_summary( string $token ) {
		$job = self::get();

		return ( $job['token'] ?? '' ) === $token ? self::summary( $job ) : self::replaced_error();
	}

	/**
	 * Continue reading a feed: the next pages of a preview that is still
	 * loading, or the page that failed (try again).
	 *
	 * @param string $token  Token from preview().
	 * @param float  $budget Seconds to spend reading pages (0: default).
	 * @return array<string, mixed>|\WP_Error Summary for the UI.
	 */
	public static function preview_more( string $token, float $budget = 0.0 ) {
		$deadline = microtime( true ) + ( $budget > 0 ? $budget : self::request_seconds() );
		$job      = self::get();

		if ( '' === $token || ( $job['token'] ?? '' ) !== $token || ! in_array( $job['status'] ?? '', [ 'loading', 'ready' ], true ) || empty( $job['catalog'] ) ) {
			return self::is_active( $job ) ? self::running_error() : self::expired_error();
		}

		// Read the missing part again, from the page that was not read.
		if ( 'ready' === $job['status'] && empty( $job['catalog']['complete'] ) && '' !== (string) $job['catalog']['next'] ) {
			$job = self::job_update(
				static function ( array $current ) use ( $token ) {
					if ( ( $current['token'] ?? '' ) !== $token || 'ready' !== ( $current['status'] ?? '' ) ) {
						return null;
					}
					$current['status']             = 'loading';
					$current['catalog']['reason']  = '';
					$current['catalog']['error']   = '';
					$current['catalog']['details'] = '';
					$current['catalog']['url']     = '';
					$current['touched']            = time();
					return $current;
				}
			);
			if ( null === $job ) {
				return self::expired_error();
			}
		}

		$loaded = self::load( $token, $deadline );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		return self::own_summary( $token );
	}

	/**
	 * Read further pages of a loading job until the catalog ends or this
	 * request's budget is used up.
	 *
	 * @param string $token      Job token.
	 * @param float  $deadline   microtime() to stop at.
	 * @param bool   $progressed Whether this request already read a page.
	 * @return true|\WP_Error
	 */
	private static function load( string $token, float $deadline, bool $progressed = false ) {
		$max_pages = max( 1, (int) apply_filters( 'epm_import_max_pages', 50 ) );
		$max_bytes = max( 1, (int) apply_filters( 'epm_import_max_bytes', 200 * MB_IN_BYTES ) );

		for ( $guard = 0; $guard < 10000; $guard++ ) {
			$job = self::get();
			if ( ( $job['token'] ?? '' ) !== $token ) {
				return self::replaced_error();
			}
			if ( 'loading' !== ( $job['status'] ?? '' ) ) {
				return true;
			}

			$catalog = (array) $job['catalog'];
			$next    = (string) $catalog['next'];

			if ( '' === $next ) {
				// The last page linked on with something that is no web
				// address: the rest cannot be read.
				$bad = (string) ( $catalog['bad_next'] ?? '' );
				return '' === $bad
					? self::finish_loading( $token, 'complete' )
					: self::finish_loading( $token, 'parse_error', __( 'The feed links to its next page with an address that is not a web address.', 'elementor-podcast-manager' ), $bad );
			}
			if ( (int) $catalog['loaded'] >= $max_pages ) {
				return self::finish_loading( $token, 'page_limit', '', $next );
			}
			if ( (int) $catalog['bytes'] >= $max_bytes ) {
				return self::finish_loading( $token, 'budget', size_format( $max_bytes ), $next );
			}
			// The rest in the next request: no proxy timeout, no memory limit.
			// (Every request reads at least one page, so the check always
			// gets on.)
			if ( $progressed && ( microtime( true ) >= $deadline || self::memory_low() ) ) {
				return true;
			}

			$page = Hosting::fetch( $next );
			if ( is_wp_error( $page ) ) {
				$error_code = $page->get_error_code();
				$reason     = ( 'epm_feed_http' === $error_code || 0 === strpos( $error_code, 'epm_feed_http_' ) || 'epm_feed_blocked' === $error_code )
					? 'http_error'
					: ( 'epm_feed_url' === $error_code ? 'parse_error' : 'transport_error' );
				return self::finish_loading( $token, $reason, $page->get_error_message(), $next, (string) ( $page->get_error_data()['details'] ?? '' ) );
			}

			$parsed = ( new FeedParser() )->parse( (string) $page['body'] );
			if ( is_wp_error( $parsed ) ) {
				return self::finish_loading( $token, 'parse_error', $parsed->get_error_message(), $next );
			}

			// A page without episodes that links on is not the end of the
			// feed: something went wrong at the host.
			if ( empty( $parsed['items'] ) && '' !== self::next_page( $job, $parsed, (string) $page['final_url'] ) ) {
				return self::finish_loading( $token, 'empty_page_with_next', '', $next );
			}

			$recorded = self::record_page( $job, $next, (string) $page['final_url'], $parsed, strlen( (string) $page['body'] ) );
			unset( $page, $parsed );
			if ( is_wp_error( $recorded ) ) {
				return $recorded;
			}
			$progressed = true;
		}

		return true;
	}

	/**
	 * The next page to read after a page, resolved against the page's
	 * address ('' at the end of the feed, or when it points to a page that
	 * was already read).
	 *
	 * @param array<string, mixed> $job      Job.
	 * @param array<string, mixed> $parsed   Parsed page.
	 * @param string               $page_url Address the page was read from (after redirects).
	 * @return string
	 */
	private static function next_page( array $job, array $parsed, string $page_url ): string {
		$next = self::next_href( $parsed, $page_url );
		if ( '' === $next ) {
			return '';
		}

		$seen = (array) ( $job['catalog']['pages'] ?? [] );

		return in_array( md5( $next ), $seen, true ) || md5( $next ) === md5( $page_url ) ? '' : $next;
	}

	/**
	 * A page's rel="next" address, resolved against the page's address
	 * (relative and root-relative links are legal in Atom); '' when there
	 * is none or it is no http(s) address.
	 *
	 * @param array<string, mixed> $parsed   Parsed page.
	 * @param string               $page_url Page address.
	 * @return string
	 */
	private static function next_href( array $parsed, string $page_url ): string {
		$href = trim( (string) ( $parsed['channel']['next'] ?? '' ) );

		return '' === $href ? '' : Hosting::sanitize_feed_url( \WP_Http::make_absolute_url( $href, $page_url ) );
	}

	/**
	 * Store one page's items and move the cursor past it.
	 *
	 * @param array<string, mixed> $job       Job (as read before the page was fetched).
	 * @param string               $requested Address that was requested (the cursor).
	 * @param string               $final     Address it was read from (after redirects).
	 * @param array<string, mixed> $parsed    Parsed page.
	 * @param int                  $bytes     Response size.
	 * @return true|\WP_Error
	 */
	private static function record_page( array $job, string $requested, string $final, array $parsed, int $bytes ) {
		$token = (string) $job['token'];
		$store = (string) $job['store'];
		$seen  = self::seen( $store, (array) $job['catalog']['segments'] );
		if ( is_wp_error( $seen ) ) {
			return self::storage_error( $token, $seen );
		}

		// Episodes already on this site (one query per page).
		$importer = new Importer();

		$unique = [];
		$hashes = [];
		$stats  = [
			'existing'   => 0,
			'with_audio' => 0,
			'tracked'    => 0,
			'blocked'    => 0,
			'newest'     => 0,
			'oldest'     => 0,
			'duplicates' => [],
		];
		foreach ( (array) $parsed['items'] as $item ) {
			$guid = trim( (string) ( $item['guid'] ?? '' ) );
			$hash = ImportStore::hash( $guid );
			// Apple ignores duplicate-GUID episodes too; the first one wins.
			if ( isset( $seen[ $hash ] ) ) {
				$stats['duplicates'][] = (string) ( $item['title'] ?? $guid );
				continue;
			}
			$seen[ $hash ] = true;
			$unique[]      = $item;
			$hashes[]      = $hash;

			if ( $importer->exists( $guid ) ) {
				++$stats['existing'];
			}
			if ( '' !== (string) ( $item['audio_url'] ?? '' ) ) {
				++$stats['with_audio'];
				if ( self::has_measurement_prefix( (string) $item['audio_url'] ) ) {
					++$stats['tracked'];
				}
			}
			if ( ! empty( $item['block'] ) ) {
				++$stats['blocked'];
			}
			$date = (int) ( $item['pub_date'] ?? 0 );
			if ( $date > 0 ) {
				$stats['newest'] = max( $stats['newest'], $date );
				$stats['oldest'] = 0 === $stats['oldest'] ? $date : min( $stats['oldest'], $date );
			}
		}

		$stored = ImportStore::put_page( $store, $unique, $hashes );
		if ( is_wp_error( $stored ) ) {
			return self::storage_error( $token, $stored );
		}

		$base   = '' !== $final ? $final : $requested;
		$next   = self::next_page( $job, $parsed, $base );
		$href   = trim( (string) ( $parsed['channel']['next'] ?? '' ) );
		$bad    = '' !== $href && '' === self::next_href( $parsed, $base ) ? $href : '';
		$loaded = (int) $job['catalog']['loaded'];
		$saved  = self::job_update(
			static function ( array $current ) use ( $token, $requested, $final, $loaded, $next, $bad, $bytes, $stored, $unique, $stats ) {
				if ( ( $current['token'] ?? '' ) !== $token || 'loading' !== ( $current['status'] ?? '' ) || (string) $current['catalog']['next'] !== $requested || (int) $current['catalog']['loaded'] !== $loaded ) {
					return null;
				}
				$catalog               = (array) $current['catalog'];
				$catalog['loaded']     = $loaded + 1;
				$catalog['pages']      = array_values( array_unique( array_merge( (array) $catalog['pages'], [ md5( $requested ), md5( $final ) ] ) ) );
				$catalog['next']       = $next;
				$catalog['bad_next']   = $bad;
				$catalog['bytes']      = (int) $catalog['bytes'] + $bytes;
				$catalog['segments'][] = $stored['segment'];
				$catalog['items']      = (int) $catalog['items'] + count( $unique );
				$current['catalog']    = $catalog;
				$current['total']      = (int) $catalog['items'];

				$all = (array) $current['stats'];
				foreach ( [ 'existing', 'with_audio', 'tracked', 'blocked' ] as $key ) {
					$all[ $key ] = (int) ( $all[ $key ] ?? 0 ) + $stats[ $key ];
				}
				if ( $stats['newest'] > 0 ) {
					$all['newest'] = max( (int) ( $all['newest'] ?? 0 ), $stats['newest'] );
					$all['oldest'] = (int) ( $all['oldest'] ?? 0 ) > 0 ? min( (int) $all['oldest'], $stats['oldest'] ) : $stats['oldest'];
				}
				$all['duplicate_count'] = (int) ( $all['duplicate_count'] ?? 0 ) + count( $stats['duplicates'] );
				$all['duplicates']      = array_slice( array_merge( (array) ( $all['duplicates'] ?? [] ), $stats['duplicates'] ), 0, 100 );
				$current['stats']       = $all;
				$current['touched']     = time();

				return $current;
			}
		);

		if ( null === $saved ) {
			// Replaced, cancelled or read by another request meanwhile: what
			// this request stored is nobody's.
			ImportStore::delete( $stored['rows'] );
			self::$seen = [];
			$now        = self::get();
			return ( $now['token'] ?? '' ) === $token && in_array( $now['status'] ?? '', [ 'loading', 'ready' ], true ) ? true : self::replaced_error();
		}

		// The next page of this request checks against these too.
		self::$seen['segments'][] = $stored['segment'];
		self::$seen['hashes']     = $seen;

		return true;
	}

	/**
	 * GUID hashes of every item stored so far: read once per request, then
	 * kept up to date page by page.
	 *
	 * @param string   $store    Storage key.
	 * @param string[] $segments Index segments of the stored pages.
	 * @return array<string, true>|\WP_Error
	 */
	private static function seen( string $store, array $segments ) {
		$known = (array) ( self::$seen['segments'] ?? [] );
		if ( ( self::$seen['store'] ?? '' ) !== $store || array_slice( $segments, 0, count( $known ) ) !== $known ) {
			self::$seen = [
				'store'    => $store,
				'segments' => [],
				'hashes'   => [],
			];
			$known      = [];
		}

		$more = ImportStore::hashes( $store, array_slice( $segments, count( $known ) ) );
		if ( is_wp_error( $more ) ) {
			self::$seen = [];
			return $more;
		}
		self::$seen['segments'] = $segments;
		self::$seen['hashes']   = self::$seen['hashes'] + $more;

		return self::$seen['hashes'];
	}

	/**
	 * End the reading of a feed: build the import order and record why the
	 * catalog ends.
	 *
	 * @param string $token  Job token.
	 * @param string $reason complete, http_error, transport_error, parse_error, empty_page_with_next, page_limit or budget.
	 * @param string $error  The concrete error ('' when none).
	 * @param string $url    The page that was not read ('' when complete).
	 * @param string $details Optional transport diagnostics.
	 * @return true|\WP_Error
	 */
	private static function finish_loading( string $token, string $reason, string $error = '', string $url = '', string $details = '' ) {
		$job = self::get();
		if ( ( $job['token'] ?? '' ) !== $token || 'loading' !== ( $job['status'] ?? '' ) ) {
			return true;
		}

		$sorted = ImportStore::sort( (string) $job['store'], (array) $job['catalog']['segments'] );
		if ( is_wp_error( $sorted ) ) {
			return self::storage_error( $token, $sorted );
		}

		$version = (int) ( $job['version'] ?? 0 );
		$before  = (array) $job['catalog']['sorted'];
		$saved   = self::job_update(
			static function ( array $current ) use ( $token, $version, $reason, $error, $url, $details, $sorted ) {
				if ( ( $current['token'] ?? '' ) !== $token || 'loading' !== ( $current['status'] ?? '' ) || (int) ( $current['version'] ?? 0 ) !== $version ) {
					return null;
				}
				$current['status']              = 'ready';
				$current['catalog']['complete'] = 'complete' === $reason;
				$current['catalog']['reason']   = $reason;
				$current['catalog']['error']    = $error;
				$current['catalog']['details']  = $details;
				$current['catalog']['url']      = $url;
				$current['catalog']['sorted']   = $sorted['rows'];
				$current['total']               = (int) $sorted['count'];
				$current['touched']             = time();
				return $current;
			}
		);

		if ( null === $saved ) {
			ImportStore::delete_ids( (string) $job['store'], 's', $sorted['rows'] );
			return true;
		}
		ImportStore::delete_ids( (string) $job['store'], 's', $before );

		return true;
	}

	/**
	 * Whether this request is close to its memory limit (the rest of the
	 * feed is then read by the next request).
	 *
	 * @return bool
	 */
	private static function memory_low(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		// Room for one more large page (500 items: about 10 MB).
		return $limit > 0 && memory_get_usage() > $limit - max( 24 * MB_IN_BYTES, (int) ( $limit / 4 ) );
	}

	/**
	 * Summary of a previewed feed for the import screens and WP-CLI.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	public static function summary( array $job ): array {
		$channel  = (array) ( $job['channel'] ?? [] );
		$stats    = (array) ( $job['stats'] ?? [] );
		$feed_url = (string) ( $job['feed_url'] ?? '' );
		$provider = $job['provider'] ?? null;
		$new_feed = (string) ( $channel['new_feed_url'] ?? '' );
		unset( $channel['description_html'] );

		return [
			'token'         => (string) ( $job['token'] ?? '' ),
			'status'        => (string) ( $job['status'] ?? '' ),
			'feed_url'      => $feed_url,
			'provider'      => $provider,
			'provider_name' => null !== $provider ? (string) ( Providers::get( (string) $provider )['name'] ?? '' ) : '',
			'channel'       => $channel,
			'episodes'      => (int) ( $job['catalog']['items'] ?? $job['total'] ?? 0 ),
			'with_audio'    => (int) ( $stats['with_audio'] ?? 0 ),
			'existing'      => (int) ( $stats['existing'] ?? 0 ),
			'newest'        => (int) ( $stats['newest'] ?? 0 ) > 0 ? date_i18n( get_option( 'date_format' ), (int) $stats['newest'] ) : '',
			'oldest'        => (int) ( $stats['oldest'] ?? 0 ) > 0 ? date_i18n( get_option( 'date_format' ), (int) $stats['oldest'] ) : '',
			// Only a real move counts (many hosts point the tag at themselves).
			'locked'        => ! empty( $channel['locked'] ),
			'duplicates'    => array_values( (array) ( $stats['duplicates'] ?? [] ) ),
			'tracked'       => (int) ( $stats['tracked'] ?? 0 ),
			'blocked'       => (int) ( $stats['blocked'] ?? 0 ),
			'podcast_guid'  => (string) ( $channel['podcast_guid'] ?? '' ),
			'moved_to'      => ( '' !== $new_feed && untrailingslashit( Hosting::sanitize_feed_url( $new_feed ) ) !== untrailingslashit( $feed_url ) ) ? $new_feed : '',
			'catalog'       => self::catalog_state( $job ),
		];
	}

	/**
	 * How complete the read catalog is, for the screens and WP-CLI (null
	 * for an import started by 1.3.0, which did not record it).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|null
	 */
	public static function catalog_state( array $job ): ?array {
		if ( empty( $job['catalog'] ) ) {
			return null;
		}

		$catalog = (array) $job['catalog'];
		$reason  = (string) $catalog['reason'];
		$loading = 'loading' === ( $job['status'] ?? '' );

		return [
			'complete' => ! $loading && ! empty( $catalog['complete'] ),
			'loading'  => $loading,
			'reason'   => $reason,
			'error'    => (string) $catalog['error'],
			'details'  => (string) ( $catalog['details'] ?? '' ),
			'url'      => (string) $catalog['url'],
			'pages'    => (int) $catalog['loaded'],
			'episodes' => (int) $catalog['items'],
			// A failed page may answer next time; a limit stays a limit.
			'retry'    => in_array( $reason, [ 'http_error', 'transport_error', 'parse_error', 'empty_page_with_next' ], true ),
			'message'  => self::catalog_message( $job ),
		];
	}

	/**
	 * The catalog's state in words.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return string
	 */
	private static function catalog_message( array $job ): string {
		$catalog  = (array) $job['catalog'];
		$pages    = (int) $catalog['loaded'];
		$episodes = (int) $catalog['items'];
		$count    = number_format_i18n( $episodes );
		$page     = number_format_i18n( $pages + 1 );

		if ( 'loading' === ( $job['status'] ?? '' ) ) {
			return sprintf(
				/* translators: 1: number of the feed page being read, 2: number of episodes found so far */
				_n( 'Reading page %1$s of the feed… %2$s episode found so far.', 'Reading page %1$s of the feed… %2$s episodes found so far.', $episodes, 'elementor-podcast-manager' ),
				$page,
				$count
			);
		}

		switch ( (string) $catalog['reason'] ) {
			case 'complete':
				return $pages > 1 ? sprintf(
					/* translators: %s: number of feed pages */
					_n( 'All %s page of the feed was read.', 'All %s pages of the feed were read.', $pages, 'elementor-podcast-manager' ),
					number_format_i18n( $pages )
				) : '';

			case 'http_error':
			case 'transport_error':
			case 'parse_error':
				return sprintf(
					/* translators: 1: page number, 2: page address, 3: error message, 4: number of episodes found */
					_n( 'Page %1$s of the feed (%2$s) could not be read: %3$s Only %4$s episode was found on the pages before it. The episodes on that page and any later pages are missing.', 'Page %1$s of the feed (%2$s) could not be read: %3$s Only %4$s episodes were found on the pages before it. The episodes on that page and any later pages are missing.', $episodes, 'elementor-podcast-manager' ),
					$page,
					(string) $catalog['url'],
					rtrim( (string) $catalog['error'], '. ' ) . '.',
					$count
				);

			case 'empty_page_with_next':
				return sprintf(
					/* translators: 1: page number, 2: page address, 3: number of episodes found */
					_n( 'Page %1$s of the feed (%2$s) lists no episodes but links to further pages. Only %3$s episode was found on the pages before it; later episodes may be missing.', 'Page %1$s of the feed (%2$s) lists no episodes but links to further pages. Only %3$s episodes were found on the pages before it; later episodes may be missing.', $episodes, 'elementor-podcast-manager' ),
					$page,
					(string) $catalog['url'],
					$count
				);

			case 'page_limit':
				return sprintf(
					/* translators: 1: maximum number of pages, 2: number of episodes found */
					_n( 'The feed has more than %1$s pages, and the import reads at most %1$s. Only the %2$s episode on those pages was found; the episodes on later pages are missing.', 'The feed has more than %1$s pages, and the import reads at most %1$s. Only the %2$s episodes on those pages were found; the episodes on later pages are missing.', $episodes, 'elementor-podcast-manager' ),
					number_format_i18n( $pages ),
					$count
				);

			case 'budget':
				return sprintf(
					/* translators: 1: size limit, e.g. "200 MB", 2: number of episodes found */
					_n( 'The feed is larger than the import reads (%1$s). Only %2$s episode was found; the episodes on later pages are missing.', 'The feed is larger than the import reads (%1$s). Only %2$s episodes were found; the episodes on later pages are missing.', $episodes, 'elementor-podcast-manager' ),
					(string) $catalog['error'],
					$count
				);
		}

		return '';
	}

	/**
	 * Remove a job's stored items (and a 1.3.0 job's file).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private static function discard( array $job ): void {
		if ( '' !== (string) ( $job['store'] ?? '' ) ) {
			ImportStore::purge( (string) $job['store'] );
		}
		self::delete_legacy_file( (string) ( $job['file'] ?? '' ) );
	}

	/**
	 * A storage error while reading a feed: when another preview replaced
	 * (and removed) this job meanwhile, that is the reason to report.
	 *
	 * @param string    $token Job token.
	 * @param \WP_Error $error Storage error.
	 * @return \WP_Error
	 */
	private static function storage_error( string $token, \WP_Error $error ): \WP_Error {
		$job = self::get();
		if ( ( $job['token'] ?? '' ) !== $token ) {
			return self::replaced_error();
		}

		return 'cancelled' === ( $job['status'] ?? '' ) ? new \WP_Error( 'epm_import_cancelled', __( 'This feed check was cancelled.', 'elementor-podcast-manager' ) ) : $error;
	}

	/**
	 * Error: an import is running.
	 *
	 * @return \WP_Error
	 */
	private static function running_error(): \WP_Error {
		return new \WP_Error( 'epm_import_running', __( 'An import is already running. Wait for it to finish or cancel it first.', 'elementor-podcast-manager' ) );
	}

	/**
	 * Error: the previewed job is gone.
	 *
	 * @return \WP_Error
	 */
	private static function expired_error(): \WP_Error {
		return new \WP_Error( 'epm_import_token', __( 'This import expired. Check the feed again to start a new one.', 'elementor-podcast-manager' ) );
	}

	/**
	 * Error: another preview replaced this one while it was reading.
	 *
	 * @return \WP_Error
	 */
	private static function replaced_error(): \WP_Error {
		return new \WP_Error( 'epm_import_replaced', __( 'Another feed check (in another tab or by another administrator) replaced this one. Check the feed again.', 'elementor-podcast-manager' ) );
	}

	/* ---------------------------------------------------------------------
	 * Cleanup: expired previews, orphaned data, the 1.3.0 folder.
	 * ------------------------------------------------------------------- */

	/**
	 * Seconds a checked feed waits to be imported (filter epm_import_ttl).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'epm_import_ttl', DAY_IN_SECONDS ) );
	}

	/**
	 * Run the cleanup once a checked feed could expire.
	 *
	 * @param int $created When the job was created.
	 * @return void
	 */
	private static function schedule_cleanup( int $created ): void {
		$when = max( time(), $created ) + self::ttl() + MINUTE_IN_SECONDS;
		$next = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( false === $next || $next > $when ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
			wp_schedule_single_event( $when, self::CLEANUP_HOOK );
		}
	}

	/**
	 * Cron (and after an update): expire previews nobody imported, remove
	 * stored data no job uses any more and the 1.3.0 import folder.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		$job    = self::get();
		$status = (string) ( $job['status'] ?? '' );
		$since  = max( (int) ( $job['created'] ?? 0 ), (int) ( $job['touched'] ?? 0 ) );

		// A checked feed nobody imported (or a 1.3.0 preview, whose
		// completeness is unknown): expired, its data removed.
		if ( in_array( $status, [ 'loading', 'ready' ], true ) && ( time() - $since > self::ttl() || ! empty( $job['file'] ) ) ) {
			$token   = (string) ( $job['token'] ?? '' );
			$expired = self::job_update(
				static function ( array $current ) use ( $token ) {
					if ( ( $current['token'] ?? '' ) !== $token || ! in_array( $current['status'] ?? '', [ 'loading', 'ready' ], true ) ) {
						return null;
					}
					$current['status']   = 'expired';
					$current['finished'] = time();
					return $current;
				}
			);
			if ( null !== $expired ) {
				self::discard( $expired );
				$job    = $expired;
				$status = 'expired';
			}
		}

		if ( in_array( $status, [ 'done', 'cancelled', 'failed', 'expired' ], true ) ) {
			self::discard( $job );
		}

		self::sweep();
		self::cleanup_legacy_folder();
		MediaDownload::cleanup_stale( self::media_files( self::get() ) );

		$job = self::get();
		if ( in_array( $job['status'] ?? '', [ 'loading', 'ready' ], true ) ) {
			self::schedule_cleanup( max( (int) ( $job['created'] ?? 0 ), (int) ( $job['touched'] ?? 0 ) ) );
		}
	}

	/**
	 * Remove stored data of jobs that are not the current one (a preview
	 * replaced while it was still reading, a request that died). Data
	 * younger than an hour stays: it may belong to a preview that is
	 * being saved right now.
	 *
	 * @return void
	 */
	private static function sweep(): void {
		$job     = self::get();
		$current = in_array( $job['status'] ?? '', [ 'loading', 'ready', 'running', 'waiting', 'done_with_problems' ], true ) ? (string) ( $job['store'] ?? '' ) : '';
		foreach ( ImportStore::keys() as $key => $created ) {
			if ( $key !== $current && time() - $created > HOUR_IN_SECONDS ) {
				ImportStore::purge( $key );
			}
		}
	}

	/**
	 * Admin requests: clean up after an update from 1.3.0 (its folder in
	 * uploads) and keep the cleanup scheduled while a checked feed waits.
	 *
	 * @return void
	 */
	public static function maybe_cleanup(): void {
		$dir = self::legacy_dir();
		if ( '' !== $dir && is_dir( $dir ) ) {
			self::cleanup();
			return;
		}

		$job = self::get();
		if ( in_array( $job['status'] ?? '', [ 'loading', 'ready' ], true ) && false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			self::schedule_cleanup( max( (int) ( $job['created'] ?? 0 ), (int) ( $job['touched'] ?? 0 ) ) );
		}
	}

	/**
	 * The 1.3.0 import folder (uploads/epm-import/), '' when unknown.
	 *
	 * @return string
	 */
	private static function legacy_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return empty( $uploads['error'] ) ? trailingslashit( (string) $uploads['basedir'] ) . 'epm-import/' : '';
	}

	/**
	 * Delete a 1.3.0 job file.
	 *
	 * @param string $file Path.
	 * @return void
	 */
	private static function delete_legacy_file( string $file ): void {
		if ( '' !== $file && file_exists( $file ) && false !== strpos( wp_normalize_path( $file ), '/epm-import/' ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Remove the 1.3.0 import folder: every file in it, unless a running
	 * 1.3.0 import still reads its file (it moves to the database with its
	 * next step, see migrate_legacy()).
	 *
	 * @return void
	 */
	private static function cleanup_legacy_folder(): void {
		$dir = self::legacy_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$job  = self::get();
		$keep = 'running' === ( $job['status'] ?? '' ) && ! empty( $job['file'] ) ? wp_normalize_path( (string) $job['file'] ) : '';
		$left = false;
		foreach ( (array) scandir( $dir ) as $name ) {
			$path = $dir . $name;
			if ( ! is_file( $path ) ) {
				continue;
			}
			if ( wp_normalize_path( $path ) === $keep ) {
				$left = true;
				continue;
			}
			wp_delete_file( $path );
		}
		if ( ! $left ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	/**
	 * Move a running 1.3.0 import (items in a JSON file in uploads) to the
	 * database storage. The file is removed afterwards.
	 *
	 * @param array<string, mixed> $job Job (running, with 'file').
	 * @return array<string, mixed>|\WP_Error The migrated job.
	 */
	private static function migrate_legacy( array $job ) {
		$file  = (string) ( $job['file'] ?? '' );
		$items = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file of 1.3.0.
		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'epm_import_missing', __( 'The stored feed data is missing. Check the feed again to restart the import.', 'elementor-podcast-manager' ) );
		}

		// 1.3.0 stored the items already de-duplicated and in import order.
		$store  = ImportStore::new_key();
		$items  = array_values( $items );
		$hashes = array_map(
			static function ( $item ) {
				return ImportStore::hash( trim( (string) ( $item['guid'] ?? '' ) ) );
			},
			$items
		);
		$stored = ImportStore::put_page( $store, $items, $hashes );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		// The file is already in import order (by date, undated last, feed
		// order among equals); sorting by the same rule keeps that order,
		// so the job's position stays valid.
		$sorted = ImportStore::sort( $store, [ $stored['segment'] ] );
		if ( is_wp_error( $sorted ) ) {
			ImportStore::purge( $store );
			return $sorted;
		}

		$token    = (string) $job['token'];
		$migrated = self::job_update(
			static function ( array $current ) use ( $token, $store, $sorted ) {
				if ( ( $current['token'] ?? '' ) !== $token || empty( $current['file'] ) ) {
					return null;
				}
				unset( $current['file'] );
				$current['store']  = $store;
				$current['legacy'] = true;
				$current['sorted'] = $sorted['rows'];
				$current['total']  = (int) $sorted['count'];
				return $current;
			}
		);
		if ( null === $migrated ) {
			ImportStore::purge( $store );
			return new \WP_Error( 'epm_import_missing', __( 'The stored feed data is missing. Check the feed again to restart the import.', 'elementor-podcast-manager' ) );
		}
		self::delete_legacy_file( $file );

		return $migrated;
	}

	/**
	 * Whether an audio URL passes through an analytics redirect (Podtrac,
	 * OP3, Chartable, Podsights …). Those keep working after an import as
	 * long as the service exists; the preview mentions them.
	 *
	 * @param string $url Audio URL.
	 * @return bool
	 */
	public static function has_measurement_prefix( string $url ): bool {
		return (bool) preg_match( '#^https?://(?:[^/]+\.)?(?:podtrac\.com|op3\.dev|pdst\.fm|chtbl\.com|chrt\.fm|gum\.fm|arttrk\.com|mgln\.ai|podscribe\.com|swap\.fm|claritaspod\.com|pfx\.vpixl\.com|prfx\.byspotify\.com|verifi\.podscribe\.com|pscrb\.fm|podkite\.com)/#i', $url );
	}

	/**
	 * Zeroed counters.
	 *
	 * @return array<string, int>
	 */
	private static function empty_counts(): array {
		return [
			'created'      => 0,
			'updated'      => 0,
			'unchanged'    => 0,
			'skipped'      => 0,
			'failed'       => 0,
			// Not an outcome of its own: episodes (counted above) whose
			// audio could not be copied and still loads from the old host.
			'media_failed' => 0,
		];
	}

	/**
	 * Start a previewed job.
	 *
	 * @param string               $token   Token from preview().
	 * @param array<string, mixed> $options status, download_media, apply_channel, overwrite_channel.
	 * @return array<string, mixed>|\WP_Error Client state.
	 */
	public static function start( string $token, array $options ) {
		$purpose = 'move' === ( $options['purpose'] ?? '' ) ? 'move' : 'mirror';
		$error   = null;

		// Claim the previewed job: only one start wins, and only while it is
		// still the job that was previewed.
		$job = self::job_update(
			static function ( array $job ) use ( $token, $options, $purpose, &$error ) {
				if ( '' === $token || ( $job['token'] ?? '' ) !== $token || 'ready' !== ( $job['status'] ?? '' ) ) {
					$error = new \WP_Error( 'epm_import_token', __( 'This import expired. Check the feed again to start a new one.', 'elementor-podcast-manager' ) );
					return null;
				}

				// A locked feed may only move with its owner's consent.
				if ( 'move' === $purpose && ! empty( $job['channel']['locked'] ) && empty( $options['confirm_owner'] ) ) {
					$error = new \WP_Error( 'epm_import_locked', __( 'This feed is locked against moving to another platform. If it is your show, confirm that you own it, or unlock the feed at your current host first (the setting is often called “Lock feed”).', 'elementor-podcast-manager' ) );
					return null;
				}

				// A move finishes with this site publishing the feed (and the
				// old host's account closed later): only with the whole
				// catalog, or when the missing part was accepted knowingly.
				if ( 'move' === $purpose && empty( $job['catalog']['complete'] ) && empty( $options['accept_partial'] ) ) {
					$error = new \WP_Error( 'epm_import_incomplete', __( 'The feed could not be read completely, so moving it now would leave episodes behind. Try reading the rest of the feed again, or confirm that you want to move only the episodes that were found.', 'elementor-podcast-manager' ) );
					return null;
				}

				$job['options'] = [
					'status'         => 'draft' === ( $options['status'] ?? '' ) ? 'draft' : 'publish',
					'download_media' => ! empty( $options['download_media'] ),
					'purpose'        => $purpose,
					'accept_partial' => empty( $job['catalog']['complete'] ) && ! empty( $options['accept_partial'] ),
				];
				$job['status']  = 'running';
				$job['started'] = time();
				$job['touched'] = time();

				return $job;
			}
		);

		if ( null === $job ) {
			return $error instanceof \WP_Error ? $error : new \WP_Error( 'epm_import_token', __( 'This import expired. Check the feed again to start a new one.', 'elementor-podcast-manager' ) );
		}

		$changed = [];
		if ( ! empty( $options['apply_channel'] ) ) {
			$changed = Importer::apply_channel( (array) $job['channel'], ! empty( $options['overwrite_channel'] ), true, 'move' === $purpose ? (string) $job['feed_url'] : '' );
		}

		// A move keeps the show's podcast:guid (apps and OP3 statistics are
		// keyed by it), whatever else is taken from the feed. It replaces a
		// guid this site only derived for itself (on the first feed request),
		// never one adopted earlier or set on purpose.
		if ( 'move' === $purpose ) {
			$stored = (string) get_option( Feed::GUID_OPTION, '' );
			Importer::adopt_podcast_guid( (array) $job['channel'], (string) $job['feed_url'], '' === $stored || Feed::derived_podcast_guid() === $stored );
		}

		if ( ! empty( $changed ) ) {
			$job = self::job_update(
				static function ( array $current ) use ( $token, $changed ) {
					if ( ( $current['token'] ?? '' ) !== $token ) {
						return null;
					}
					$current['settings_changed'] = $changed;
					return $current;
				}
			) ?? self::get();
		}

		self::schedule_continuation();

		return self::client_state( $job );
	}

	/**
	 * Whether a job is an import in progress (running, or waiting for its
	 * host).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return bool
	 */
	public static function is_active( array $job ): bool {
		return in_array( $job['status'] ?? '', [ 'running', 'waiting' ], true );
	}

	/**
	 * Whether the last import was a move that is not finished because files
	 * stayed at the old host (or episodes failed).
	 *
	 * @return bool
	 */
	public static function move_unfinished(): bool {
		$job = self::get();

		return 'done_with_problems' === ( $job['status'] ?? '' ) && 'move' === ( $job['options']['purpose'] ?? '' );
	}

	/**
	 * The step this request is working on: token and position (null when
	 * none). A request that dies while it is set is noticed by the shutdown
	 * handler.
	 *
	 * @var array{token: string, position: int}|null
	 */
	private static ?array $working = null;

	/**
	 * Whether the shutdown handler is registered in this request.
	 *
	 * @var bool
	 */
	private static bool $guarded = false;

	/**
	 * Import the next batch.
	 *
	 * @param float $budget Seconds to spend (a media download of the batch
	 *                      gets at most this, and at most
	 *                      MediaDownload::request_seconds()).
	 * @return array<string, mixed> Client state.
	 */
	public static function step( float $budget = 8.0 ) {
		$job = self::get();

		// The host asked to wait: nothing happens before that time.
		if ( 'waiting' === ( $job['status'] ?? '' ) && time() < (int) ( $job['wait_until'] ?? 0 ) ) {
			return self::client_state( $job );
		}
		if ( ! self::is_active( $job ) ) {
			return self::client_state( $job );
		}

		if ( ! self::acquire_lock() ) {
			$state         = self::client_state( $job );
			$state['busy'] = true;
			return $state;
		}

		try {
			// What happened before the lock was taken counts, not what was
			// read before (another request may have finished, cancelled or
			// replaced the job meanwhile).
			$job = self::get();
			if ( 'waiting' === ( $job['status'] ?? '' ) && time() >= (int) ( $job['wait_until'] ?? 0 ) ) {
				$token = (string) ( $job['token'] ?? '' );
				$job   = self::job_update(
					static function ( array $current ) use ( $token ) {
						if ( 'waiting' !== ( $current['status'] ?? '' ) || ( $current['token'] ?? '' ) !== $token ) {
							return null;
						}
						$current['status'] = 'running';
						unset( $current['wait_until'], $current['wait_reason'] );
						return $current;
					}
				) ?? self::get();
			}
			if ( 'running' !== ( $job['status'] ?? '' ) ) {
				return self::client_state( $job );
			}

			$token = (string) ( $job['token'] ?? '' );

			// An import 1.3.0 was running when the plugin was updated: its
			// items move from the uploads folder to the database first.
			if ( ! empty( $job['file'] ) && empty( $job['store'] ) ) {
				$migrated = self::migrate_legacy( $job );
				if ( is_wp_error( $migrated ) ) {
					return self::fail( $token, $migrated->get_error_message() );
				}
				$job = $migrated;
			}

			$store    = (string) ( $job['store'] ?? '' );
			$sorted   = (array) ( $job['catalog']['sorted'] ?? $job['sorted'] ?? [] );
			$total    = (int) ( $job['total'] ?? 0 );
			$started  = microtime( true );
			$download = ! empty( $job['options']['download_media'] );
			$budget   = max( 1.0, $budget );

			$importer = new Importer(
				[
					'feed_url'       => (string) $job['feed_url'],
					'status'         => (string) ( $job['options']['status'] ?? 'publish' ),
					'download_media' => $download,
					// Media downloads of this request end by then; a file that
					// takes longer is continued by the next request.
					'deadline'       => $started + min( $budget, MediaDownload::request_seconds() ),
					'author'         => (int) ( $job['user'] ?? 0 ),
				]
			);
			$GLOBALS['epm_import_step_active'] = true;
			$GLOBALS['epm_import_step_dirty'] = false;

			if ( $download ) {
				// Download files of requests that died (never the one this job continues).
				MediaDownload::cleanup_stale( self::media_files( $job ) );
				self::guard_shutdown();
			}

			$done = 0;
			$lost = false;
			while ( (int) $job['position'] < $total && $done < 10 && ( microtime( true ) - $started ) < $budget ) {
				// Before every episode: the lock is still this request's, and
				// the job is still running, the same job, at the same place
				// (Cancel or a new preview end the batch here).
				if ( ! self::keep_lock() ) {
					$lost = true;
					break;
				}
				$fresh = self::get();
				if ( 'running' !== ( $fresh['status'] ?? '' ) || ( $fresh['token'] ?? '' ) !== $token || (int) ( $fresh['position'] ?? -1 ) !== (int) $job['position'] ) {
					break;
				}

				$position = (int) $job['position'];
				$item     = ImportStore::item( $store, $sorted, $position );
				if ( null === $item ) {
					return self::fail( $token, __( 'The stored feed data is missing. Check the feed again to restart the import.', 'elementor-podcast-manager' ) );
				}

				$resume = [];
				if ( $download ) {
					$resume = self::begin_item( $token, $position );
					if ( null === $resume ) {
						break;
					}
				}

				if ( isset( $resume['outcome'] ) ) {
					// Interrupted too often: given up, with the reason.
					$outcome = (array) $resume['outcome'];
				} else {
					self::$working = [
						'token'    => $token,
						'position' => $position,
					];
					try {
						$outcome = $importer->import_item( $item, $resume );
					} catch ( \Throwable $e ) {
						if ( $download ) {
							self::interrupted( $token, $position, $e->getMessage() );
						}
						throw $e;
					} finally {
						self::$working = null;
					}
				}
				unset( $item );

				// A request that took the lock over (this episode took longer
				// than the lock lives) handles the job now; its own check of
				// this episode counts, not this one.
				if ( ! self::keep_lock() ) {
					$lost = true;
					break;
				}

				// A copy that continues in the next request (or after the
				// wait the host asked for): the episode stays in place.
				if ( ! empty( $outcome['pending'] ) ) {
					$saved = self::job_update(
						static function ( array $current ) use ( $token, $position, $outcome, $resume ) {
							if ( 'running' !== ( $current['status'] ?? '' ) || ( $current['token'] ?? '' ) !== $token || (int) ( $current['position'] ?? -1 ) !== $position ) {
								return null;
							}
							return self::hold( $current, $position, $outcome, $resume );
						}
					);
					if ( null !== $saved ) {
						$job = $saved;
					}
					break;
				}

				// Count what happened on the job as it is now: a Cancel that
				// came while the episode was imported stays, and the episode
				// is counted once.
				$saved = self::job_update(
					static function ( array $current ) use ( $token, $position, $outcome ) {
						if ( ( $current['token'] ?? '' ) !== $token || (int) ( $current['position'] ?? -1 ) !== $position ) {
							return null;
						}
						unset( $current['media']['inflight'] );
						return self::record( $current, $outcome );
					}
				);
				if ( null === $saved ) {
					break;
				}
				$job = $saved;
				++$done;

				if ( 'running' !== ( $job['status'] ?? '' ) ) {
					break;
				}
			}

			if ( $lost ) {
				$GLOBALS['epm_import_step_active'] = false;
				if ( ! empty( $GLOBALS['epm_import_step_dirty'] ) ) {
					Feed::flush_cache();
				}
				$state         = self::client_state( self::get() );
				$state['busy'] = self::is_active( self::get() );
				return $state;
			}
			$GLOBALS['epm_import_step_active'] = false;
			if ( ! empty( $GLOBALS['epm_import_step_dirty'] ) ) {
				Feed::flush_cache();
			}
			unset( $GLOBALS['epm_import_step_active'], $GLOBALS['epm_import_step_dirty'], $GLOBALS['epm_import_guid_map'] );

			if ( 'waiting' === ( $job['status'] ?? '' ) ) {
				// The background run takes over at the time the host named.
				wp_clear_scheduled_hook( self::CRON_HOOK );
				wp_schedule_single_event( (int) $job['wait_until'] + 1, self::CRON_HOOK );
			} elseif ( 'running' === ( $job['status'] ?? '' ) && (int) $job['position'] >= $total ) {
				self::finish( $token, $total );
			} elseif ( 'running' === ( $job['status'] ?? '' ) ) {
				self::schedule_continuation();
			}

			return self::client_state( self::get() );
		} finally {
			unset( $GLOBALS['epm_import_step_active'], $GLOBALS['epm_import_step_dirty'] );
			self::release_lock();
		}
	}

	/**
	 * The job went through every episode: done, or for a move that left
	 * files at the old host (or failed episodes) "done_with_problems",
	 * which does not finish the move. Only by the request that still holds
	 * the lock, and only a job nobody cancelled or replaced meanwhile.
	 *
	 * @param string $token Job token.
	 * @param int    $total Episodes.
	 * @return void
	 */
	private static function finish( string $token, int $total ): void {
		if ( ! self::keep_lock() ) {
			return;
		}

		$finished = self::job_update(
			static function ( array $current ) use ( $token, $total ) {
				if ( 'running' !== ( $current['status'] ?? '' ) || ( $current['token'] ?? '' ) !== $token || (int) ( $current['position'] ?? 0 ) < $total ) {
					return null;
				}
				$move               = 'move' === ( $current['options']['purpose'] ?? '' );
				$current['status']  = $move && self::has_problems( $current ) ? 'done_with_problems' : 'done';
				$current['finished'] = time();
				unset( $current['media']['inflight'] );
				return $current;
			}
		);
		if ( null === $finished ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		Feed::flush_cache();

		// Unfinished: the parsed feed stays for a retry.
		if ( 'done_with_problems' === $finished['status'] ) {
			return;
		}

		self::discard( $finished );
		// A move of an incomplete catalog finishes only when that was
		// accepted (an import started by 1.3.0 did not know).
		if ( 'move' === ( $finished['options']['purpose'] ?? '' ) && ( ! empty( $finished['catalog']['complete'] ) || ! empty( $finished['options']['accept_partial'] ) || empty( $finished['catalog'] ) ) ) {
			self::finish_move();
		}
	}

	/**
	 * Whether a job left episodes behind: failed episodes, or files that
	 * still load from the old host.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return bool
	 */
	private static function has_problems( array $job ): bool {
		return (int) ( $job['counts']['failed'] ?? 0 ) > 0 || array_sum( array_map( 'intval', (array) ( $job['media']['counts'] ?? [] ) ) ) > 0;
	}

	/**
	 * Before an episode of a "copy media" job: mark it as being worked on,
	 * so a request that dies there (and could not say so) is noticed by the
	 * next one. An episode interrupted too often is given up.
	 *
	 * @param string $token    Job token.
	 * @param int    $position Position.
	 * @return array<string, mixed>|null What earlier requests left for this episode
	 *                                   (with `outcome` when it is given up); null
	 *                                   when the job changed meanwhile.
	 */
	private static function begin_item( string $token, int $position ): ?array {
		$died  = false;
		$saved = self::job_update(
			static function ( array $current ) use ( $token, $position, &$died ) {
				if ( 'running' !== ( $current['status'] ?? '' ) || ( $current['token'] ?? '' ) !== $token || (int) ( $current['position'] ?? -1 ) !== $position ) {
					return null;
				}
				$inflight = (array) ( $current['media']['inflight'] ?? [] );
				if ( (int) ( $inflight['position'] ?? -1 ) !== $position ) {
					$inflight = [ 'position' => $position ];
				}
				// Still marked from an earlier request: it died without a word.
				$died = ! empty( $inflight['running'] );
				if ( $died ) {
					$inflight['tries']      = (int) ( $inflight['tries'] ?? 0 ) + 1;
					$inflight['last_error'] = __( 'The request ended without a response (it was stopped by the server).', 'elementor-podcast-manager' );
				}
				$inflight['running']           = true;
				$current['media']['inflight'] = $inflight;
				return $current;
			}
		);
		if ( null === $saved ) {
			return null;
		}

		$inflight = (array) $saved['media']['inflight'];
		$tries    = (int) ( $inflight['tries'] ?? 0 );
		if ( $tries < MediaDownload::max_attempts() ) {
			return $inflight;
		}

		// Given up: the kind that was being copied fails with the reason; an
		// episode that died elsewhere fails as a whole.
		MediaDownload::discard( (array) ( $inflight['download'] ?? [] ) );
		$reason = sprintf(
			/* translators: 1: number of attempts, 2: error message */
			__( 'The copy was interrupted %1$d times (last: %2$s).', 'elementor-podcast-manager' ),
			$tries,
			rtrim( (string) ( $inflight['last_error'] ?? '' ), '.' ) . '.'
		);
		$kind = (string) ( $inflight['kind'] ?? '' );
		if ( '' !== $kind ) {
			$inflight['done'][ $kind ] = [
				'state'  => 'failed',
				'url'    => (string) ( $inflight['download']['url'] ?? '' ),
				'code'   => 'interrupted',
				'reason' => $reason,
			];
			$inflight['download']      = [];
			$inflight['kind']          = '';
			$inflight['tries']         = 0;
			return $inflight;
		}

		$inflight['outcome'] = [
			'action'       => 'failed',
			'id'           => 0,
			'title'        => '',
			'message'      => $reason,
			'media_failed' => false,
			'media'        => [],
			'remaining'    => [],
			'pending'      => null,
		];

		return $inflight;
	}

	/**
	 * Keep an episode whose copy continues in the next request (or after
	 * the wait its host asked for) at its place.
	 *
	 * @param array<string, mixed> $job      Job.
	 * @param int                  $position Position.
	 * @param array<string, mixed> $outcome  Outcome with `pending`.
	 * @param array<string, mixed> $resume   What earlier requests left.
	 * @return array<string, mixed>
	 */
	private static function hold( array $job, int $position, array $outcome, array $resume ): array {
		$pending = (array) $outcome['pending'];
		$settled = [];
		foreach ( (array) ( $outcome['media'] ?? [] ) as $kind => $media ) {
			if ( $kind !== ( $pending['kind'] ?? '' ) && in_array( $media['state'] ?? '', [ 'failed', 'busy', 'copied' ], true ) ) {
				$settled[ $kind ] = $media;
			}
		}

		$job['media']['inflight'] = [
			'position' => $position,
			'running'  => false,
			'action'   => in_array( $resume['action'] ?? '', [ 'created', 'updated' ], true ) ? $resume['action'] : (string) $outcome['action'],
			'id'       => (int) $outcome['id'],
			'title'    => (string) $outcome['title'],
			'kind'     => (string) ( $pending['kind'] ?? '' ),
			'download' => (array) ( $pending['download'] ?? [] ),
			'done'     => $settled,
			'tries'    => (int) ( $resume['tries'] ?? 0 ),
		];

		$until = (int) ( $pending['until'] ?? 0 );
		if ( $until > time() ) {
			$job['status']      = 'waiting';
			$job['wait_until']  = $until;
			$job['wait_reason'] = (string) ( $pending['reason'] ?? '' );
		}
		$job['touched'] = time();

		return $job;
	}

	/**
	 * Register the shutdown handler once per request.
	 *
	 * @return void
	 */
	private static function guard_shutdown(): void {
		if ( self::$guarded ) {
			return;
		}
		self::$guarded = true;
		register_shutdown_function( [ self::class, 'on_shutdown' ] );

		// After a fatal error WordPress's own handler (an earlier shutdown
		// function) ends the request with wp_die(), so later shutdown
		// functions never run: clean up in its wp_die() handler first.
		foreach ( [ 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' ] as $hook ) {
			add_filter( $hook, [ self::class, 'die_handler' ], PHP_INT_MAX );
		}
	}

	/**
	 * The wp_die() handler while a fatal error ends a step: clean up (see
	 * on_shutdown()), then let the original handler end the request.
	 *
	 * @param callable|string $handler Handler.
	 * @return callable|string
	 */
	public static function die_handler( $handler ) {
		$error = error_get_last();
		if ( null === self::$working || ! is_array( $error ) || ! in_array( (int) $error['type'], self::FATAL, true ) ) {
			return $handler;
		}

		return static function ( ...$args ) use ( $handler ) {
			self::on_shutdown();
			return call_user_func_array( $handler, $args );
		};
	}

	/**
	 * Error types that end a request.
	 */
	private const FATAL = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];

	/**
	 * Shutdown: the request is ending in the middle of an episode (a fatal
	 * error such as the memory limit, the time limit, an exit). The copy's
	 * files are removed, the attempt is counted with its reason, the lock
	 * is released and the background run is scheduled, so the import goes
	 * on.
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		$working = self::$working;
		if ( null === $working ) {
			return;
		}
		self::$working = null;

		// Room to clean up after a memory error.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$error   = error_get_last();
		$message = is_array( $error ) && in_array( (int) $error['type'], self::FATAL, true )
			? (string) $error['message']
			: __( 'The request ended in the middle of the copy.', 'elementor-podcast-manager' );

		self::interrupted( (string) $working['token'], (int) $working['position'], $message );
		self::release_lock();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
	}

	/**
	 * An episode's copy was interrupted: remove its files and count the
	 * attempt with its reason (the episode is tried again, up to
	 * MediaDownload::max_attempts() times).
	 *
	 * @param string $token    Job token.
	 * @param int    $position Position.
	 * @param string $message  What happened.
	 * @return void
	 */
	private static function interrupted( string $token, int $position, string $message ): void {
		$abandoned = MediaCopy::abandon();
		$kind      = [
			'audio'      => 'audio',
			'image'      => 'image',
			'transcript' => 'transcript_file',
		][ (string) ( $abandoned['kind'] ?? '' ) ] ?? '';

		self::job_update(
			static function ( array $current ) use ( $token, $position, $message, $kind, $abandoned ) {
				if ( 'running' !== ( $current['status'] ?? '' ) || ( $current['token'] ?? '' ) !== $token || (int) ( $current['position'] ?? -1 ) !== $position ) {
					return null;
				}
				$inflight = (array) ( $current['media']['inflight'] ?? [] );
				if ( (int) ( $inflight['position'] ?? -1 ) !== $position ) {
					$inflight = [ 'position' => $position ];
				}
				MediaDownload::discard( (array) ( $inflight['download'] ?? [] ) );
				$inflight['running']    = false;
				$inflight['tries']      = (int) ( $inflight['tries'] ?? 0 ) + 1;
				$inflight['last_error'] = $message;
				$inflight['download']   = '' !== $kind ? [ 'url' => (string) ( $abandoned['url'] ?? '' ) ] : [];
				if ( '' !== $kind ) {
					$inflight['kind'] = $kind;
				}
				$current['media']['inflight'] = $inflight;
				$current['touched']            = time();
				return $current;
			}
		);
	}

	/**
	 * Download files the job still needs (the copy it continues).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return string[]
	 */
	private static function media_files( array $job ): array {
		$file = (string) ( $job['media']['inflight']['download']['file'] ?? '' );

		return '' !== $file && self::is_active( $job ) ? [ $file ] : [];
	}

	/**
	 * Mark the running job failed (only the job this request worked on,
	 * only while it is running) and remove its stored data.
	 *
	 * @param string $token   Job token.
	 * @param string $message Error.
	 * @return array<string, mixed> Client state.
	 */
	private static function fail( string $token, string $message ): array {
		$failed = self::job_update(
			static function ( array $current ) use ( $token, $message ) {
				if ( 'running' !== ( $current['status'] ?? '' ) || ( $current['token'] ?? '' ) !== $token ) {
					return null;
				}
				$current['status']   = 'failed';
				$current['error']    = $message;
				$current['finished'] = time();
				return $current;
			}
		);
		if ( null !== $failed ) {
			MediaDownload::discard( (array) ( $failed['media']['inflight']['download'] ?? [] ) );
			self::discard( $failed );
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		return self::client_state( self::get() );
	}

	/**
	 * Kinds of files that can stay at the old host, in the order they are
	 * listed.
	 */
	private const REMAINING_KINDS = [ 'audio', 'image', 'transcript_file', 'transcript_link' ];

	/**
	 * Episodes listed per kind on the job (the counts go on beyond that).
	 */
	private const REMAINING_LIST = 500;

	/**
	 * Record one item's outcome on the job and move past it.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $outcome Outcome of Importer::import_item().
	 * @return array<string, mixed>
	 */
	private static function record( array $job, array $outcome ): array {
		$job['counts'] = wp_parse_args( (array) ( $job['counts'] ?? [] ), self::empty_counts() );
		$action        = isset( $job['counts'][ $outcome['action'] ] ) && 'media_failed' !== $outcome['action'] ? $outcome['action'] : 'skipped';
		++$job['counts'][ $action ];

		if ( ! empty( $outcome['media_failed'] ) ) {
			++$job['counts']['media_failed'];
			$job['media_failed_ids'] = array_slice( array_merge( (array) ( $job['media_failed_ids'] ?? [] ), [ (int) $outcome['id'] ] ), -500 );
		}

		// What was copied, and what still points to the old host (and why).
		$id = (int) $outcome['id'];
		foreach ( (array) ( $outcome['media'] ?? [] ) as $kind => $media ) {
			if ( 'copied' === ( $media['state'] ?? '' ) ) {
				$job['media']['copied'][ $kind ] = (int) ( $job['media']['copied'][ $kind ] ?? 0 ) + 1;
			}
		}
		foreach ( (array) ( $outcome['remaining'] ?? [] ) as $kind => $url ) {
			$job['media']['counts'][ $kind ] = (int) ( $job['media']['counts'][ $kind ] ?? 0 ) + 1;
			if ( $id > 0 && ( isset( $job['media']['refs'][ $id ] ) || count( (array) ( $job['media']['refs'] ?? [] ) ) < self::REMAINING_LIST ) ) {
				$job['media']['refs'][ $id ][ $kind ] = [
					'url'    => (string) $url,
					'reason' => (string) ( $outcome['media'][ $kind ]['reason'] ?? self::remaining_reason( $kind ) ),
				];
			}
		}

		$log = (array) ( $job['log'] ?? [] );
		array_unshift(
			$log,
			[
				'title'   => $outcome['title'],
				'action'  => $action,
				'message' => $outcome['message'],
				'id'      => $id,
			]
		);
		$job['log']      = array_slice( $log, 0, 50 );
		$job['position'] = (int) ( $job['position'] ?? 0 ) + 1;
		$job['touched']  = time();

		return $job;
	}

	/**
	 * Why a file stayed at the old host when no copy was tried.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function remaining_reason( string $kind ): string {
		if ( 'transcript_link' === $kind ) {
			return __( 'Only WebVTT and SRT transcript files are copied; transcripts in other formats (such as JSON) stay linked at the old host.', 'elementor-podcast-manager' );
		}

		return __( 'Not copied in this run.', 'elementor-podcast-manager' );
	}

	/**
	 * A count of files of a kind, in words ("2 audio files").
	 *
	 * @param string $kind  Kind.
	 * @param int    $count Number.
	 * @return string
	 */
	public static function kind_label( string $kind, int $count ): string {
		$number = number_format_i18n( $count );
		switch ( $kind ) {
			case 'audio':
				/* translators: %s: number of files */
				return sprintf( _n( '%s audio file', '%s audio files', $count, 'elementor-podcast-manager' ), $number );
			case 'image':
				/* translators: %s: number of images */
				return sprintf( _n( '%s episode image', '%s episode images', $count, 'elementor-podcast-manager' ), $number );
			case 'transcript_file':
				/* translators: %s: number of files */
				return sprintf( _n( '%s transcript file (WebVTT/SRT)', '%s transcript files (WebVTT/SRT)', $count, 'elementor-podcast-manager' ), $number );
		}

		/* translators: %s: number of transcripts */
		return sprintf( _n( '%s transcript in another format (linked)', '%s transcripts in other formats (linked)', $count, 'elementor-podcast-manager' ), $number );
	}

	/**
	 * After moving a show here: every episode must stay in the feed (a
	 * missing item reads as a takedown to Spotify), the feed announces its
	 * new home and is locked against unauthorized moves.
	 *
	 * This website now hosts the show: a site that mirrored its old host
	 * stops syncing and stops redirecting the feed there (otherwise the
	 * old host's redirect back to this feed would make a loop).
	 *
	 * @return void
	 */
	private static function finish_move(): void {
		$settings = epm()->settings;
		$values   = $settings->all();
		$limit    = (int) $values['feed_limit'];

		if ( $limit > 0 && epm()->episodes->count_published() > $limit ) {
			$values['feed_limit'] = 0;
		}
		$values['moved_in'] = true;
		$values['locked']   = '' !== (string) $values['owner_email'];

		update_option( PodcastSettings::OPTION, $settings->sanitize( $values ) );

		if ( Hosting::is_external() ) {
			$hosting         = Hosting::all();
			$hosting['mode'] = 'self';
			update_option( Hosting::OPTION, Hosting::sanitize( $hosting ) );
		}
	}

	/**
	 * Copy again what a move left at the old host: the job goes through
	 * its episodes once more (from the stored feed, nothing is read again);
	 * files that are here already are not requested. When nothing is left,
	 * the move finishes.
	 *
	 * @return array<string, mixed>|\WP_Error Client state.
	 */
	public static function retry() {
		$job = self::job_update(
			static function ( array $current ) {
				if ( 'done_with_problems' !== ( $current['status'] ?? '' ) || empty( $current['store'] ) ) {
					return null;
				}
				$current['status']   = 'running';
				$current['position'] = 0;
				$current['counts']   = self::empty_counts();
				$current['media']    = [];
				$current['retries']  = (int) ( $current['retries'] ?? 0 ) + 1;
				$current['touched']  = time();
				unset( $current['finished'], $current['media_failed_ids'] );
				return $current;
			}
		);
		if ( null === $job ) {
			return new \WP_Error( 'epm_import_retry', __( 'There is nothing to try again. Check the feed again to start a new import.', 'elementor-podcast-manager' ) );
		}

		self::schedule_continuation();

			return self::client_state( $job );
	}

	/**
	 * Finish a move although files stay at the old host: the site owner
	 * was shown which ones and confirmed.
	 *
	 * @return array<string, mixed>|\WP_Error Client state.
	 */
	public static function confirm_move() {
		$job = self::job_update(
			static function ( array $current ) {
				if ( 'done_with_problems' !== ( $current['status'] ?? '' ) || 'move' !== ( $current['options']['purpose'] ?? '' ) ) {
					return null;
				}
				$current['status']    = 'done';
				$current['confirmed'] = time();
				return $current;
			}
		);
		if ( null === $job ) {
			return new \WP_Error( 'epm_import_confirm', __( 'There is no unfinished move to confirm.', 'elementor-podcast-manager' ) );
		}

		self::discard( $job );
		self::finish_move();
		Feed::flush_cache();

		return self::client_state( $job );
	}

	/**
	 * Cancel the job. Episodes already imported stay.
	 *
	 * @return array<string, mixed>
	 */
	public static function cancel(): array {
		// A batch that is running finishes the episode in flight and then
		// stops (it checks the job before every episode); a cancelled job
		// is never started again. An unfinished move stays unfinished.
		$cancelled = self::job_update(
			static function ( array $job ) {
				if ( ! in_array( $job['status'] ?? '', [ 'loading', 'ready', 'running', 'waiting', 'done_with_problems' ], true ) ) {
					return null;
				}
				$job['status']   = 'cancelled';
				$job['finished'] = time();
				return $job;
			}
		);

		if ( null !== $cancelled ) {
			MediaDownload::discard( (array) ( $cancelled['media']['inflight']['download'] ?? [] ) );
			self::discard( $cancelled );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );

		return self::client_state( self::get() );
	}

	/**
	 * Keep a running job going without a browser.
	 *
	 * @return void
	 */
	private static function schedule_continuation(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Cron: continue a job nobody is stepping through.
	 *
	 * @return void
	 */
	public static function run_in_background(): void {
		$job = self::get();

		if ( 'waiting' === ( $job['status'] ?? '' ) && time() < (int) ( $job['wait_until'] ?? 0 ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( (int) $job['wait_until'] + 1, self::CRON_HOOK );
			}
			return;
		}

		if ( ! self::is_active( $job ) ) {
			return;
		}

		// The import screen is open and stepping: stay out of its way.
		if ( 'running' === $job['status'] && time() - (int) ( $job['touched'] ?? 0 ) < 45 ) {
			self::schedule_continuation();
			return;
		}

		$deadline = microtime( true ) + 20;
		do {
			$state = self::step( 8.0 );
		} while ( 'running' === $state['status'] && empty( $state['busy'] ) && microtime( true ) < $deadline );

		if ( 'running' === $state['status'] ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * State for the import screen.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	public static function client_state( array $job ): array {
		$log = [];
		$log_ids = array_slice( array_values( array_unique( array_map( static function ( $entry ) { return (int) ( $entry['id'] ?? 0 ); }, (array) ( $job['log'] ?? [] ) ) ) ), 0, 50 );
		if ( ! empty( $log_ids ) ) {
			_prime_post_caches( $log_ids, false, false );
		}
		foreach ( (array) ( $job['log'] ?? [] ) as $entry ) {
			$id    = (int) ( $entry['id'] ?? 0 );
			$log[] = [
				'title'   => (string) ( $entry['title'] ?? '' ),
				'action'  => (string) ( $entry['action'] ?? '' ),
				'message' => (string) ( $entry['message'] ?? '' ),
				'edit'    => $id > 0 ? (string) get_edit_post_link( $id, 'raw' ) : '',
			];
		}

		// Episodes whose audio stayed at the old host (newest first).
		$media_failed = [];
		$failed_ids   = array_slice( array_reverse( array_unique( array_map( 'intval', (array) ( $job['media_failed_ids'] ?? [] ) ) ) ), 0, 100 );
		$refs         = (array) ( $job['media']['refs'] ?? [] );
		$prime        = array_unique( array_merge( $failed_ids, array_map( 'intval', array_keys( $refs ) ) ) );
		if ( ! empty( $prime ) ) {
			_prime_post_caches( $prime, false, false );
		}
		foreach ( $failed_ids as $id ) {
			if ( $id <= 0 || ! get_post( $id ) ) {
				continue;
			}
			$media_failed[] = [
				'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			];
		}

		$state = [
			'status'       => (string) ( $job['status'] ?? 'none' ),
			'total'        => (int) ( $job['total'] ?? 0 ),
			'done'         => (int) ( $job['position'] ?? 0 ),
			'counts'       => wp_parse_args( (array) ( $job['counts'] ?? [] ), self::empty_counts() ),
			'media_failed' => $media_failed,
			// What still loads from the old host, per kind, with the reason.
			'remaining'    => self::remaining( $job ),
			'copied'       => array_map( 'intval', (array) ( $job['media']['copied'] ?? [] ) ),
			'log'          => $log,
			'user'         => (int) ( $job['user'] ?? 0 ),
			'feed_url'     => (string) ( $job['feed_url'] ?? '' ),
			'title'        => (string) ( $job['channel']['title'] ?? '' ),
			'error'        => (string) ( $job['error'] ?? '' ),
			'settings'     => array_values( (array) ( $job['settings_changed'] ?? [] ) ),
			'episodes'     => admin_url( 'edit.php?post_type=' . EpisodePostType::CPT ),
			// How complete the imported catalog was (null: started by 1.3.0).
			'catalog'      => self::catalog_state( $job ),
			'purpose'      => (string) ( $job['options']['purpose'] ?? '' ),
			'copy_media'   => ! empty( $job['options']['download_media'] ),
			'wait_until'   => 'waiting' === ( $job['status'] ?? '' ) ? (int) ( $job['wait_until'] ?? 0 ) : 0,
			'wait_reason'  => 'waiting' === ( $job['status'] ?? '' ) ? (string) ( $job['wait_reason'] ?? '' ) : '',
			'current'      => self::current_copy( $job ),
			'confirmed'    => (int) ( $job['confirmed'] ?? 0 ),
			'can_retry'    => 'done_with_problems' === ( $job['status'] ?? '' ) && ! empty( $job['store'] ),
		];

		$state['problems'] = 'done_with_problems' === $state['status'] ? self::problems_text( $job, $state['remaining'] ) : '';

		return $state;
	}

	/**
	 * What still loads from the old host, per kind: how many episodes, a
	 * label, and the episodes (title, edit link, address, reason).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, array{count: int, label: string, episodes: array<int, array<string, string>>}>
	 */
	private static function remaining( array $job ): array {
		$refs = (array) ( $job['media']['refs'] ?? [] );
		$out  = [];
		foreach ( self::REMAINING_KINDS as $kind ) {
			$count = (int) ( $job['media']['counts'][ $kind ] ?? 0 );
			if ( $count <= 0 ) {
				continue;
			}
			$episodes = [];
			foreach ( $refs as $id => $kinds ) {
				if ( ! isset( $kinds[ $kind ] ) || count( $episodes ) >= 100 ) {
					continue;
				}
				$episodes[] = [
					'title'  => get_post( (int) $id ) ? html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ) : '',
					'edit'   => (string) get_edit_post_link( (int) $id, 'raw' ),
					'url'    => (string) $kinds[ $kind ]['url'],
					'reason' => (string) $kinds[ $kind ]['reason'],
				];
			}
			$out[ $kind ] = [
				'count'    => $count,
				'label'    => self::kind_label( $kind, $count ),
				'episodes' => $episodes,
			];
		}

		return $out;
	}

	/**
	 * What an unfinished move leaves behind, in one sentence (also what the
	 * confirmation names).
	 *
	 * @param array<string, mixed>                $job       Job.
	 * @param array<string, array<string, mixed>> $remaining From remaining().
	 * @return string
	 */
	private static function problems_text( array $job, array $remaining ): string {
		$parts = array_map(
			static function ( $group ) {
				return (string) $group['label'];
			},
			$remaining
		);
		$failed = (int) ( $job['counts']['failed'] ?? 0 );
		if ( $failed > 0 ) {
			/* translators: %s: number of episodes */
			$parts[] = sprintf( _n( '%s episode that could not be imported', '%s episodes that could not be imported', $failed, 'elementor-podcast-manager' ), number_format_i18n( $failed ) );
		}

		return sprintf(
			/* translators: %s: list such as "1 audio file, 2 episode images" */
			__( 'Still at the old host: %s. Copy them again, or finish the move and keep them there (they stop working when the old account is closed).', 'elementor-podcast-manager' ),
			implode( ', ', $parts )
		);
	}

	/**
	 * The copy in progress (a large file over several requests).
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array{title: string, kind: string, bytes: int, total: int}|null
	 */
	private static function current_copy( array $job ): ?array {
		$inflight = (array) ( $job['media']['inflight'] ?? [] );
		if ( ! self::is_active( $job ) || empty( $inflight['kind'] ) || empty( $inflight['download'] ) ) {
			return null;
		}

		return [
			'title' => (string) ( $inflight['title'] ?? '' ),
			'kind'  => (string) $inflight['kind'],
			'bytes' => (int) ( $inflight['download']['bytes'] ?? 0 ),
			'total' => (int) ( $inflight['download']['total'] ?? 0 ),
		];
	}

	/**
	 * Common AJAX guard.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Reload the page and try again.', 'elementor-podcast-manager' ) ], 403 );
		}

		if ( ! Capabilities::can_manage_podcast() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to import episodes.', 'elementor-podcast-manager' ) ], 403 );
		}
	}

	/**
	 * AJAX: preview a feed.
	 *
	 * @return void
	 */
	public function ajax_preview(): void {
		$this->guard();

		$url     = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$summary = self::preview( $url );

		if ( is_wp_error( $summary ) ) {
			wp_send_json_error( [ 'message' => $summary->get_error_message(), 'details' => (string) ( $summary->get_error_data()['details'] ?? '' ) ] );
		}

		wp_send_json_success( $summary );
	}

	/**
	 * AJAX: continue reading a feed (the next pages, or the page that
	 * failed).
	 *
	 * @return void
	 */
	public function ajax_more(): void {
		$this->guard();

		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$summary = self::preview_more( $token );

		if ( is_wp_error( $summary ) ) {
			wp_send_json_error( [ 'message' => $summary->get_error_message(), 'details' => (string) ( $summary->get_error_data()['details'] ?? '' ) ] );
		}

		wp_send_json_success( $summary );
	}

	/**
	 * AJAX: start an import.
	 *
	 * @return void
	 */
	public function ajax_start(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		$options = [
			'status'            => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : 'publish',
			'download_media'    => ! empty( $_POST['download_media'] ),
			'apply_channel'     => ! empty( $_POST['apply_channel'] ),
			'overwrite_channel' => ! empty( $_POST['overwrite_channel'] ),
			'purpose'           => isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( (string) $_POST['purpose'] ) ) : 'mirror',
			'confirm_owner'     => ! empty( $_POST['confirm_owner'] ),
			'accept_partial'    => ! empty( $_POST['accept_partial'] ),
		];
		// phpcs:enable

		$state = self::start( $token, $options );

		if ( is_wp_error( $state ) ) {
			wp_send_json_error(
				[
					'message' => $state->get_error_message(),
					'code'    => $state->get_error_code(),
				]
			);
		}

		wp_send_json_success( $state );
	}

	/**
	 * AJAX: import the next batch.
	 *
	 * @return void
	 */
	public function ajax_step(): void {
		$this->guard();
		wp_send_json_success( self::step() );
	}

	/**
	 * AJAX: cancel.
	 *
	 * @return void
	 */
	public function ajax_cancel(): void {
		$this->guard();
		wp_send_json_success( self::cancel() );
	}

	/**
	 * AJAX: copy again what a move left at the old host.
	 *
	 * @return void
	 */
	public function ajax_retry(): void {
		$this->guard();
		$state = self::retry();
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( [ 'message' => $state->get_error_message() ] );
		}
		wp_send_json_success( $state );
	}

	/**
	 * AJAX: finish a move although files stay at the old host. The request
	 * must carry the confirmation the screen asks for.
	 *
	 * @return void
	 */
	public function ajax_confirm(): void {
		$this->guard();
		if ( empty( $_POST['confirm_remaining'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
			wp_send_json_error( [ 'message' => __( 'Confirm that these files may stay at the old host.', 'elementor-podcast-manager' ) ] );
		}
		$state = self::confirm_move();
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( [ 'message' => $state->get_error_message() ] );
		}
		wp_send_json_success( $state );
	}

	/**
	 * AJAX: current state.
	 *
	 * @return void
	 */
	public function ajax_status(): void {
		$this->guard();
		wp_send_json_success( self::client_state( self::get() ) );
	}

	/**
	 * AJAX: sync with the host now.
	 *
	 * @return void
	 */
	public function ajax_sync_now(): void {
		$this->guard();

		$result = Hosting::sync( true );
		$state  = Hosting::state();

		$payload = [
			'status'   => $result['status'],
			'message'  => $result['message'],
			'last_run' => $state['last_run'] > 0 ? sprintf(
				/* translators: %s: human time difference, e.g. "5 mins" */
				__( '%s ago', 'elementor-podcast-manager' ),
				human_time_diff( (int) $state['last_run'] )
			) : '',
		];

		if ( 'ok' !== $result['status'] ) {
			wp_send_json_error( $payload );
		}

		wp_send_json_success( $payload );
	}
}
