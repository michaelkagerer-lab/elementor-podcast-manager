<?php
/**
 * Batched feed import (Layer 1).
 *
 * A show with hundreds of episodes cannot be imported in one request, so
 * the import runs as a job:
 *
 * 1. preview: fetch and parse the feed once, store the parsed items in a
 *    private file under uploads/epm-import/, and report what was found.
 * 2. start:   apply the show details (optional) and mark the job running.
 * 3. step:    import a few items per request (AJAX from the import screen).
 *    When the browser is closed, a WP-Cron event keeps the job going.
 *
 * Only one import or sync runs at a time (an option-based lock).
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
	 * Wire AJAX handlers and the background continuation.
	 *
	 * @return void
	 */
	public function init(): void {
		foreach ( [ 'preview', 'start', 'step', 'cancel', 'status' ] as $action ) {
			add_action( 'wp_ajax_epm_import_' . $action, [ $this, 'ajax_' . $action ] );
		}
		add_action( 'wp_ajax_epm_sync_now', [ $this, 'ajax_sync_now' ] );
		add_action( self::CRON_HOOK, [ self::class, 'run_in_background' ] );
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
	 * Seconds after which a lock counts as stale. One "copy media" step
	 * downloads a single audio file, which may take up to 15 minutes.
	 *
	 * @return int
	 */
	private static function lock_ttl(): int {
		wp_cache_delete( self::OPTION, 'options' );
		$job = self::get();

		return ! empty( $job['options']['download_media'] ) && 'running' === ( $job['status'] ?? '' )
			? 20 * MINUTE_IN_SECONDS
			: 5 * MINUTE_IN_SECONDS;
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
	 * Current job (empty array when none).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$job = get_option( self::OPTION, [] );

		return is_array( $job ) ? $job : [];
	}

	/**
	 * Save the job.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private static function save( array $job ): void {
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Private storage directory for parsed items.
	 *
	 * @return string|\WP_Error Absolute path with trailing slash.
	 */
	private static function storage_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'epm_import_storage', (string) $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'epm-import/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'epm_import_storage', __( 'The uploads folder is not writable, so the import cannot store its progress.', 'elementor-podcast-manager' ) );
		}

		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Remove the job's item file.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private static function delete_file( array $job ): void {
		$file = (string) ( $job['file'] ?? '' );
		if ( '' !== $file && file_exists( $file ) && false !== strpos( wp_normalize_path( $file ), '/epm-import/' ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Read the job's parsed items.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<int, array<string, mixed>>
	 */
	private static function items( array $job ): array {
		$file = (string) ( $job['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			return [];
		}

		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Fetch and analyse a feed; store it for the import.
	 *
	 * @param string $url Feed URL.
	 * @return array<string, mixed>|\WP_Error Summary for the UI.
	 */
	public static function preview( string $url ) {
		$current = self::get();
		if ( 'running' === ( $current['status'] ?? '' ) ) {
			return new \WP_Error( 'epm_import_running', __( 'An import is already running. Wait for it to finish or cancel it first.', 'elementor-podcast-manager' ) );
		}

		$located = Hosting::locate( $url );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		$fetched = $located['fetched'];

		$parsed = ( new FeedParser() )->parse( $fetched['body'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$dir = self::storage_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		self::delete_file( $current );

		// Paged feeds (SoundCloud serves 500 items per page): follow
		// rel="next" so the whole back catalog is imported.
		$pages = 1;
		$next  = (string) ( $parsed['channel']['next'] ?? '' );
		$seen  = [ $located['url'] => true ];
		while ( '' !== $next && ! isset( $seen[ $next ] ) && $pages < (int) apply_filters( 'epm_import_max_pages', 50 ) ) {
			$seen[ $next ] = true;
			$page          = Hosting::fetch( $next );
			if ( is_wp_error( $page ) ) {
				break;
			}
			$more = ( new FeedParser() )->parse( $page['body'] );
			if ( is_wp_error( $more ) || empty( $more['items'] ) ) {
				break;
			}
			$parsed['items'] = array_merge( $parsed['items'], $more['items'] );
			$next            = (string) ( $more['channel']['next'] ?? '' );
			++$pages;
		}

		[ $unique, $duplicates ] = Importer::dedupe( $parsed['items'] );

		// Oldest first, by date (some feeds are not in order): episodes are
		// created in publishing order. Undated items go last.
		$items = $unique;
		usort(
			$items,
			static function ( $a, $b ) {
				$x = (int) $a['pub_date'] > 0 ? (int) $a['pub_date'] : PHP_INT_MAX;
				$y = (int) $b['pub_date'] > 0 ? (int) $b['pub_date'] : PHP_INT_MAX;
				return $x <=> $y;
			}
		);
		$token = wp_generate_password( 24, false );
		$file  = $dir . 'job-' . $token . '.json';

		if ( false === file_put_contents( $file, (string) wp_json_encode( $items ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'epm_import_storage', __( 'The uploads folder is not writable, so the import cannot store its progress.', 'elementor-podcast-manager' ) );
		}

		$importer = new Importer();
		$existing = 0;
		$audio    = 0;
		$newest   = 0;
		$oldest   = 0;
		$tracked  = 0;
		$blocked  = 0;
		foreach ( $items as $item ) {
			if ( $importer->exists( (string) $item['guid'] ) ) {
				++$existing;
			}
			if ( '' !== (string) $item['audio_url'] ) {
				++$audio;
				if ( self::has_measurement_prefix( (string) $item['audio_url'] ) ) {
					++$tracked;
				}
			}
			if ( ! empty( $item['block'] ) ) {
				++$blocked;
			}
			$date = (int) $item['pub_date'];
			if ( $date > 0 ) {
				$newest = max( $newest, $date );
				$oldest = 0 === $oldest ? $date : min( $oldest, $date );
			}
		}

		$feed_url = (string) $located['url'];
		$provider = Providers::detect( $feed_url );
		if ( null === $provider ) {
			$provider = Providers::detect( Hosting::sanitize_feed_url( $url ) );
		}
		if ( null === $provider ) {
			$provider = Providers::detect_generator( (string) $parsed['channel']['generator'] );
		}

		$channel = $parsed['channel'];
		unset( $channel['description_html'] );

		$job = [
			'token'    => $token,
			'status'   => 'ready',
			'feed_url' => $feed_url,
			'file'     => $file,
			'channel'  => $parsed['channel'],
			'total'    => count( $items ),
			'position' => 0,
			'counts'   => self::empty_counts(),
			'log'      => [],
			'created'  => time(),
		];
		self::save( $job );

		return [
			'token'     => $token,
			'feed_url'  => $feed_url,
			'provider'  => $provider,
			'provider_name' => null !== $provider ? (string) ( Providers::get( $provider )['name'] ?? '' ) : '',
			'channel'   => $channel,
			'episodes'  => count( $items ),
			'with_audio' => $audio,
			'existing'  => $existing,
			'newest'    => $newest > 0 ? date_i18n( get_option( 'date_format' ), $newest ) : '',
			'oldest'    => $oldest > 0 ? date_i18n( get_option( 'date_format' ), $oldest ) : '',
			// Only a real move counts (many hosts point the tag at themselves).
			'locked'     => ! empty( $parsed['channel']['locked'] ),
			'duplicates' => $duplicates,
			'tracked'    => $tracked,
			'blocked'    => $blocked,
			'podcast_guid' => (string) $parsed['channel']['podcast_guid'],
			'moved_to'  => ( '' !== (string) $parsed['channel']['new_feed_url'] && untrailingslashit( Hosting::sanitize_feed_url( (string) $parsed['channel']['new_feed_url'] ) ) !== untrailingslashit( $feed_url ) ) ? (string) $parsed['channel']['new_feed_url'] : '',
		];
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
		$job = self::get();

		if ( '' === $token || ( $job['token'] ?? '' ) !== $token || 'ready' !== ( $job['status'] ?? '' ) ) {
			return new \WP_Error( 'epm_import_token', __( 'This import expired. Check the feed again to start a new one.', 'elementor-podcast-manager' ) );
		}

		$purpose = 'move' === ( $options['purpose'] ?? '' ) ? 'move' : 'mirror';

		// A locked feed may only move with its owner's consent.
		if ( 'move' === $purpose && ! empty( $job['channel']['locked'] ) && empty( $options['confirm_owner'] ) ) {
			return new \WP_Error( 'epm_import_locked', __( 'This feed is locked against moving to another platform. If it is your show, confirm that you own it, or unlock the feed at your current host first (the setting is often called “Lock feed”).', 'elementor-podcast-manager' ) );
		}

		$job['options'] = [
			'status'         => 'draft' === ( $options['status'] ?? '' ) ? 'draft' : 'publish',
			'download_media' => ! empty( $options['download_media'] ),
			'purpose'        => $purpose,
		];

		if ( ! empty( $options['apply_channel'] ) ) {
			$job['settings_changed'] = Importer::apply_channel( (array) $job['channel'], ! empty( $options['overwrite_channel'] ), true, 'move' === $purpose ? (string) $job['feed_url'] : '' );
		}

		// A move keeps the show's podcast:guid (apps and OP3 statistics are
		// keyed by it), whatever else is taken from the feed. It replaces a
		// guid this site only derived for itself (on the first feed request),
		// never one adopted earlier or set on purpose.
		if ( 'move' === $purpose ) {
			$stored = (string) get_option( Feed::GUID_OPTION, '' );
			Importer::adopt_podcast_guid( (array) $job['channel'], (string) $job['feed_url'], '' === $stored || Feed::derived_podcast_guid() === $stored );
		}

		$job['status']  = 'running';
		$job['started'] = time();
		$job['touched'] = time();
		self::save( $job );

		self::schedule_continuation();

		return self::client_state( $job );
	}

	/**
	 * Import the next batch.
	 *
	 * @param float $budget Seconds to spend.
	 * @return array<string, mixed> Client state.
	 */
	public static function step( float $budget = 8.0 ) {
		$job = self::get();

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return self::client_state( $job );
		}

		if ( ! self::acquire_lock() ) {
			$state         = self::client_state( $job );
			$state['busy'] = true;
			return $state;
		}

		try {
			$items    = self::items( $job );
			$total    = count( $items );
			$started  = microtime( true );
			$download = ! empty( $job['options']['download_media'] );
			$batch    = $download ? 1 : 10;

			if ( 0 === $total && (int) $job['total'] > 0 ) {
				$job['status'] = 'failed';
				$job['error']  = __( 'The stored feed data is missing. Check the feed again to restart the import.', 'elementor-podcast-manager' );
				self::save( $job );
				return self::client_state( $job );
			}

			$importer = new Importer(
				[
					'feed_url'       => (string) $job['feed_url'],
					'status'         => (string) ( $job['options']['status'] ?? 'publish' ),
					'download_media' => $download,
				]
			);

			$done = 0;
			while ( (int) $job['position'] < $total && $done < $batch && ( microtime( true ) - $started ) < $budget ) {
				if ( ! self::keep_lock() ) {
					break;
				}
				$item    = $items[ (int) $job['position'] ];
				$outcome = $importer->import_item( $item );

				$action = isset( $job['counts'][ $outcome['action'] ] ) ? $outcome['action'] : 'skipped';
				++$job['counts'][ $action ];

				if ( ! empty( $outcome['media_failed'] ) ) {
					$job['counts']['media_failed'] = (int) ( $job['counts']['media_failed'] ?? 0 ) + 1;
					$job['media_failed_ids']       = array_slice( array_merge( (array) ( $job['media_failed_ids'] ?? [] ), [ (int) $outcome['id'] ] ), -500 );
				}

				array_unshift(
					$job['log'],
					[
						'title'   => $outcome['title'],
						'action'  => $action,
						'message' => $outcome['message'],
						'id'      => (int) $outcome['id'],
					]
				);
				$job['log'] = array_slice( $job['log'], 0, 50 );

				++$job['position'];
				++$done;
			}

			$job['touched'] = time();

			// Cancelled (or replaced) while this batch ran: never overwrite
			// that with "running", and never finish a cancelled move. The
			// option is read past this request's cache.
			wp_cache_delete( self::OPTION, 'options' );
			$fresh = self::get();
			if ( 'running' !== ( $fresh['status'] ?? '' ) || ( $fresh['token'] ?? '' ) !== ( $job['token'] ?? '' ) ) {
				return self::client_state( $fresh );
			}

			if ( (int) $job['position'] >= $total ) {
				$job['status']   = 'done';
				$job['finished'] = time();
				self::delete_file( $job );
				if ( 'move' === ( $job['options']['purpose'] ?? '' ) ) {
					self::finish_move();
				}
				wp_clear_scheduled_hook( self::CRON_HOOK );
				Feed::flush_cache();
			} else {
				self::schedule_continuation();
			}

			self::save( $job );

			return self::client_state( $job );
		} finally {
			self::release_lock();
		}
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
	 * Cancel the job. Episodes already imported stay.
	 *
	 * @return array<string, mixed>
	 */
	public static function cancel(): array {
		$job = self::get();

		if ( in_array( $job['status'] ?? '', [ 'ready', 'running' ], true ) ) {
			self::delete_file( $job );
			$job['status']   = 'cancelled';
			$job['finished'] = time();
			self::save( $job );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );

		return self::client_state( $job );
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

		if ( 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		// The import screen is open and stepping: stay out of its way.
		if ( time() - (int) ( $job['touched'] ?? 0 ) < 45 ) {
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
		if ( ! empty( $failed_ids ) ) {
			_prime_post_caches( $failed_ids, false, false );
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

		return [
			'status'       => (string) ( $job['status'] ?? 'none' ),
			'total'        => (int) ( $job['total'] ?? 0 ),
			'done'         => (int) ( $job['position'] ?? 0 ),
			'counts'       => wp_parse_args( (array) ( $job['counts'] ?? [] ), self::empty_counts() ),
			'media_failed' => $media_failed,
			'log'          => $log,
			'feed_url'     => (string) ( $job['feed_url'] ?? '' ),
			'title'        => (string) ( $job['channel']['title'] ?? '' ),
			'error'        => (string) ( $job['error'] ?? '' ),
			'settings'     => array_values( (array) ( $job['settings_changed'] ?? [] ) ),
			'episodes'     => admin_url( 'edit.php?post_type=' . EpisodePostType::CPT ),
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
			wp_send_json_error( [ 'message' => $summary->get_error_message() ] );
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
		];
		// phpcs:enable

		$state = self::start( $token, $options );

		if ( is_wp_error( $state ) ) {
			wp_send_json_error( [ 'message' => $state->get_error_message() ] );
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
