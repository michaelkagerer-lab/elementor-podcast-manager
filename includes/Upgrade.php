<?php
/**
 * Upgrade work after a plugin update (Layer 1).
 *
 * Plugin::maybe_upgrade() runs in the first request after an update, which
 * may be any visitor's. It stores the new version first and does only
 * cheap work there (rewrite rules, feed cache). Work that touches every
 * episode is queued here and done in batches outside visitor requests:
 * by WP-Cron, a little on each admin page load, or all at once with
 * `wp podcast upgrade`.
 *
 * - durations: _epm_duration_seconds from the duration (or the audio
 *   file's length) for every episode, reading only those keys;
 * - guid_rows: episodes with more than one _epm_guid row (two requests
 *   created a GUID at the same time before 1.4) keep the row WordPress
 *   returns, the oldest one; the others are deleted.
 *
 * Progress (a cursor) is stored in a row of its own; a lock row makes sure
 * only one request works on it at a time. Both are changed with
 * conditional statements (OptionRow).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Upgrade {

	/**
	 * Option: the queued work, JSON {tasks: string[], cursor: int, from: string, removed_guids: int}.
	 */
	public const STATE = 'epm_upgrade_state';

	/**
	 * Option: lock ("<time>:<owner>").
	 */
	public const LOCK = 'epm_upgrade_lock';

	/**
	 * Cron event that continues the work.
	 */
	public const HOOK = 'epm_upgrade_step';

	/**
	 * Episodes per batch.
	 */
	private const BATCH = 200;

	/**
	 * Seconds after which a lock counts as abandoned.
	 */
	private const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Work in the order it runs.
	 */
	private const TASKS = [ 'durations', 'guid_rows' ];

	/**
	 * Wire the cron event and the admin steps.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ self::class, 'cron' ] );
		add_action( 'admin_init', [ self::class, 'admin' ], 20 );
	}

	/**
	 * Queue the per-episode work after an update.
	 *
	 * @param string $from Version updated from ('' when unknown).
	 * @return void
	 */
	public static function queue( string $from ): void {
		$state = [
			'tasks'         => self::TASKS,
			'cursor'        => 0,
			'from'          => $from,
			'removed_guids' => 0,
		];

		// A batch in progress notices the new queue (its conditional write
		// fails) and stops; the next run starts over.
		$value = (string) wp_json_encode( $state );
		for ( $try = 0; $try < 3; $try++ ) {
			$current = OptionRow::read( self::STATE );
			if ( null === $current ? OptionRow::insert( self::STATE, $value ) : ( $current === $value || OptionRow::replace( self::STATE, $current, $value ) ) ) {
				break;
			}
		}

		self::schedule();
	}

	/**
	 * Work still queued.
	 *
	 * @return string[]
	 */
	public static function pending(): array {
		return array_values( (array) ( self::state()['tasks'] ?? [] ) );
	}

	/**
	 * WP-Cron: work for up to 20 seconds, then continue in the next run.
	 *
	 * @return void
	 */
	public static function cron(): void {
		$result = self::run( 20.0 );
		if ( ! empty( $result['pending'] ) ) {
			self::schedule();
		}
	}

	/**
	 * Admin page loads: one batch, for sites where WP-Cron does not run.
	 *
	 * @return void
	 */
	public static function admin(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || empty( self::pending() ) ) {
			return;
		}

		$result = self::run( 1.0 );
		if ( ! empty( $result['pending'] ) && false === wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Work in batches until nothing is left or $seconds have passed.
	 *
	 * @param float $seconds Time budget (at least one batch runs); 0 = no limit.
	 * @return array{pending: string[], batches: int, busy: bool, removed_guids: int}
	 */
	public static function run( float $seconds = 0.0 ): array {
		$result = [
			'pending'       => self::pending(),
			'batches'       => 0,
			'busy'          => false,
			'removed_guids' => 0,
		];
		if ( empty( $result['pending'] ) ) {
			return $result;
		}

		$lock = OptionRow::lock( self::LOCK, self::LOCK_TTL );
		if ( '' === $lock ) {
			// Another request works on it.
			$result['busy'] = true;
			return $result;
		}

		$end = microtime( true ) + $seconds;
		do {
			$raw   = OptionRow::read( self::STATE );
			$state = self::decode( $raw );
			if ( null === $raw || empty( $state['tasks'] ) ) {
				break;
			}

			$state = self::batch( $state );
			$value = (string) wp_json_encode( $state );
			if ( empty( $state['tasks'] ) ) {
				// Done (unless the work was queued again meanwhile).
				OptionRow::delete( self::STATE, $raw );
			} elseif ( ! OptionRow::replace( self::STATE, $raw, $value ) ) {
				// Queued again by another update meanwhile: start over.
				break;
			}
			++$result['batches'];
			$result['removed_guids'] += (int) ( $state['removed_guids'] ?? 0 ) - (int) ( self::decode( $raw )['removed_guids'] ?? 0 );

			$lock = OptionRow::renew( self::LOCK, $lock );
			if ( '' === $lock ) {
				// Taken over (this request was too slow): stop.
				break;
			}
		} while ( ! empty( $state['tasks'] ) && ( $seconds <= 0 || microtime( true ) < $end ) );

		OptionRow::unlock( self::LOCK, $lock );

		$result['pending'] = self::pending();
		if ( empty( $result['pending'] ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		return $result;
	}

	/**
	 * One batch of the first queued task.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed> New state.
	 */
	private static function batch( array $state ): array {
		$task = (string) $state['tasks'][0];

		if ( 'durations' === $task ) {
			$ids = self::episode_ids_after( (int) ( $state['cursor'] ?? 0 ) );
			if ( empty( $ids ) ) {
				return self::next_task( $state );
			}
			Episodes::sync_durations( $ids );
			$state['cursor'] = (int) end( $ids );
			return $state;
		}

		if ( 'guid_rows' === $task ) {
			$removed = Episodes::collapse_guid_rows( self::BATCH );
			if ( 0 === $removed ) {
				return self::next_task( $state );
			}
			$state['removed_guids'] = (int) ( $state['removed_guids'] ?? 0 ) + $removed;
			return $state;
		}

		// Unknown (from a newer version): skip.
		return self::next_task( $state );
	}

	/**
	 * Move on to the next task.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function next_task( array $state ): array {
		array_shift( $state['tasks'] );
		$state['tasks']  = array_values( $state['tasks'] );
		$state['cursor'] = 0;

		return $state;
	}

	/**
	 * The next batch of episode IDs (every status but trash and auto-draft).
	 *
	 * @param int $after Last ID done.
	 * @return int[]
	 */
	private static function episode_ids_after( int $after ): array {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d", EpisodePostType::CPT, $after, self::BATCH ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a batch of IDs.
		);
	}

	/**
	 * The queued work.
	 *
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		return self::decode( OptionRow::read( self::STATE ) );
	}

	/**
	 * Decode a state row.
	 *
	 * @param string|null $value Row value.
	 * @return array<string, mixed>
	 */
	private static function decode( ?string $value ): array {
		$state = null === $value ? null : json_decode( $value, true );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * Continue in WP-Cron.
	 *
	 * @return void
	 */
	private static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}
}
