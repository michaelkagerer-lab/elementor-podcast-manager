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
	 * @return bool
	 */
	public static function acquire_lock(): bool {
		// add_option() fails when the row exists: an atomic test-and-set.
		if ( add_option( self::LOCK, (string) time(), '', false ) ) {
			return true;
		}

		$since = (int) get_option( self::LOCK, 0 );
		if ( $since > 0 && time() - $since > 5 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, (string) time(), '', false );
		}

		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public static function release_lock(): void {
		delete_option( self::LOCK );
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
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'failed'    => 0,
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
				$item    = $items[ (int) $job['position'] ];
				$outcome = $importer->import_item( $item );

				$action = isset( $job['counts'][ $outcome['action'] ] ) ? $outcome['action'] : 'skipped';
				++$job['counts'][ $action ];

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

		return [
			'status'   => (string) ( $job['status'] ?? 'none' ),
			'total'    => (int) ( $job['total'] ?? 0 ),
			'done'     => (int) ( $job['position'] ?? 0 ),
			'counts'   => wp_parse_args( (array) ( $job['counts'] ?? [] ), self::empty_counts() ),
			'log'      => $log,
			'feed_url' => (string) ( $job['feed_url'] ?? '' ),
			'title'    => (string) ( $job['channel']['title'] ?? '' ),
			'error'    => (string) ( $job['error'] ?? '' ),
			'settings' => array_values( (array) ( $job['settings_changed'] ?? [] ) ),
			'episodes' => admin_url( 'edit.php?post_type=' . EpisodePostType::CPT ),
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
				/* translators: %s: human time difference, e.g. "2 mins" */
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
