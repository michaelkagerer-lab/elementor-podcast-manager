<?php
/**
 * WP-CLI commands: `wp podcast import|cancel|finish-move|sync|status|upgrade`.
 *
 * Useful for very large catalogs (no browser request limits) and for
 * scripted migrations. Uses the same code paths as the admin screens.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cli {

	/**
	 * Register the command.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'podcast', self::class );
		}
	}

	/**
	 * Import episodes from a podcast feed.
	 *
	 * Paged feeds are read page by page. When a feed cannot be read
	 * completely (a page fails, an empty page links on, the page limit is
	 * reached), nothing is imported: the command names the page and the
	 * reason and keeps the check, so `--resume` can read the rest again
	 * later; `--accept-partial` imports only the episodes that were found.
	 *
	 * ## OPTIONS
	 *
	 * [<feed>]
	 * : RSS feed address, Apple Podcasts link or a page that links to the feed. Not needed with --resume.
	 *
	 * [--move]
	 * : Take the show over: adopt its podcast:guid, lift the feed episode limit, announce the new home and lock the feed when done.
	 *
	 * [--copy-media]
	 * : Copy audio, episode images and WebVTT/SRT transcript files into the Media Library (also for episodes that exist already). A --move that leaves files at the old host is not finished: see `wp podcast finish-move`.
	 *
	 * [--draft]
	 * : Create new episodes as drafts.
	 *
	 * [--show-details]
	 * : Fill empty podcast settings (title, description, artwork …) from the feed.
	 *
	 * [--owner]
	 * : Confirm that you own a locked feed (required to --move a locked feed).
	 *
	 * [--accept-partial]
	 * : Import even when the feed could not be read completely: only the episodes found (a --move is then finished anyway).
	 *
	 * [--resume]
	 * : Continue the last import: read the rest of a feed that could not be read completely, keep an interrupted (or waiting) import going, or copy again what a move left at the old host.
	 *
	 * ## EXAMPLES
	 *
	 *     wp podcast import https://anchor.fm/s/123abc/podcast/rss --move --copy-media --show-details --owner
	 *     wp podcast import https://podcasts.apple.com/us/podcast/example/id123456789 --draft
	 *     wp podcast import --resume --move --copy-media --owner
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$resume = isset( $assoc_args['resume'] );
		$job    = ImportJob::get();

		// An import that was interrupted (or is waiting for WP-Cron, or for
		// its host).
		if ( $resume && ImportJob::is_active( $job ) ) {
			$state = ImportJob::client_state( $job );
			\WP_CLI::log( sprintf( 'Continuing the import of %s (%d of %d done).', '' !== $state['title'] ? $state['title'] : $state['feed_url'], (int) $state['done'], (int) $state['total'] ) );
			self::report( self::run( $state ) );
			return;
		}

		// A move that left files at the old host: copy them again.
		if ( $resume && 'done_with_problems' === ( $job['status'] ?? '' ) ) {
			$state = ImportJob::retry();
			if ( is_wp_error( $state ) ) {
				\WP_CLI::error( $state->get_error_message() );
			}
			\WP_CLI::log( 'Copying again what is still at the old host.' );
			self::report( self::run( $state ) );
			return;
		}

		if ( $resume ) {
			if ( ! in_array( $job['status'] ?? '', [ 'loading', 'ready' ], true ) ) {
				\WP_CLI::error( 'There is no import to resume. Run the command with a feed address.' );
			}
			$summary = ImportJob::preview_more( (string) ( $job['token'] ?? '' ), 120.0 );
		} else {
			if ( empty( $args[0] ) ) {
				\WP_CLI::error( 'Give a feed address, or --resume to continue the last import.' );
			}
			$summary = ImportJob::preview( (string) $args[0], 120.0 );
		}

		while ( ! is_wp_error( $summary ) && ! empty( $summary['catalog']['loading'] ) ) {
			\WP_CLI::log( (string) $summary['catalog']['message'] );
			$summary = ImportJob::preview_more( (string) $summary['token'], 120.0 );
		}
		if ( is_wp_error( $summary ) ) {
			\WP_CLI::error( $summary->get_error_message() );
		}

		\WP_CLI::log(
			sprintf(
				'%s — %d episodes (%d already here)%s',
				(string) ( $summary['channel']['title'] ?? $summary['feed_url'] ),
				(int) $summary['episodes'],
				(int) $summary['existing'],
				'' !== (string) $summary['provider_name'] ? ', ' . $summary['provider_name'] : ''
			)
		);

		$catalog = (array) $summary['catalog'];
		if ( empty( $catalog['complete'] ) ) {
			if ( ! isset( $assoc_args['accept-partial'] ) ) {
				\WP_CLI::error(
					'The feed could not be read completely. ' . $catalog['message'] . "\n"
					. 'Nothing was imported. Run `wp podcast import --resume` (with the same options) to read the rest of the feed again, or add --accept-partial to import only the episodes that were found.'
				);
			}
			\WP_CLI::warning( 'The feed is incomplete. ' . $catalog['message'] . ' Importing only the episodes that were found (--accept-partial).' );
		} elseif ( '' !== (string) $catalog['message'] ) {
			\WP_CLI::log( (string) $catalog['message'] );
		}

		$state = ImportJob::start(
			(string) $summary['token'],
			[
				'purpose'           => isset( $assoc_args['move'] ) ? 'move' : 'mirror',
				'status'            => isset( $assoc_args['draft'] ) ? 'draft' : 'publish',
				'download_media'    => isset( $assoc_args['copy-media'] ),
				'apply_channel'     => isset( $assoc_args['show-details'] ),
				'overwrite_channel' => false,
				'confirm_owner'     => isset( $assoc_args['owner'] ),
				'accept_partial'    => isset( $assoc_args['accept-partial'] ),
			]
		);
		if ( is_wp_error( $state ) ) {
			\WP_CLI::error( $state->get_error_message() );
		}

		self::report( self::run( $state ) );
	}

	/**
	 * Step a running import until it stops, with a progress bar.
	 *
	 * @param array<string, mixed> $state Client state.
	 * @return array<string, mixed> Final client state.
	 */
	private static function run( array $state ): array {
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing', max( 1, (int) $state['total'] ) );
		$done     = (int) $state['done'];
		$progress->tick( $done );

		while ( ImportJob::is_active( $state ) ) {
			// The host asked to wait: wait here when it is not long.
			if ( 'waiting' === $state['status'] ) {
				$wait = (int) $state['wait_until'] - time();
				if ( $wait > self::MAX_WAIT ) {
					break;
				}
				\WP_CLI::log( sprintf( 'The old host asked to wait %d seconds (%s).', max( 0, $wait ), $state['wait_reason'] ) );
				sleep( max( 1, $wait + 1 ) );
			}
			$state = ImportJob::step( 20.0 );
			if ( ! empty( $state['busy'] ) ) {
				sleep( 2 );
				continue;
			}
			$progress->tick( max( 0, (int) $state['done'] - $done ) );
			$done = (int) $state['done'];
		}
		$progress->finish();

		return $state;
	}

	/**
	 * Longest wait for a host (seconds) the command sits through.
	 */
	private const MAX_WAIT = 600;

	/**
	 * Report a finished import (an error when it did not finish).
	 *
	 * @param array<string, mixed> $state Final client state.
	 * @return void
	 */
	private static function report( array $state ): void {
		if ( 'waiting' === $state['status'] ) {
			\WP_CLI::error(
				sprintf(
					'The old host asked to wait until %s UTC (%s). The import continues in the background then (WP-Cron), or run `wp podcast import --resume` after that time.',
					gmdate( 'Y-m-d H:i:s', (int) $state['wait_until'] ),
					$state['wait_reason']
				)
			);
		}

		if ( ! in_array( $state['status'], [ 'done', 'done_with_problems' ], true ) ) {
			\WP_CLI::error( '' !== $state['error'] ? $state['error'] : 'The import stopped: ' . $state['status'] );
		}

		$counts = $state['counts'];
		$line   = sprintf(
			'%d new, %d updated, %d unchanged, %d skipped, %d failed.',
			(int) $counts['created'],
			(int) $counts['updated'],
			(int) $counts['unchanged'],
			(int) $counts['skipped'],
			(int) $counts['failed']
		);

		// Never a plain success for part of a catalog, or for a move that
		// left files at the old host.
		$catalog    = $state['catalog'] ?? null;
		$incomplete = is_array( $catalog ) && empty( $catalog['complete'] );
		if ( $incomplete ) {
			\WP_CLI::warning( 'Imported from an incomplete feed: ' . $catalog['message'] );
		}
		if ( $incomplete || ! empty( $state['remaining'] ) || 'done_with_problems' === $state['status'] ) {
			\WP_CLI::log( 'Imported: ' . $line );
		} else {
			\WP_CLI::success( $line );
		}

		// Files that still load from the old host must be copied before
		// that account is closed: each one, with its episode and why.
		self::print_remaining( $state );

		if ( 'done_with_problems' === $state['status'] ) {
			\WP_CLI::error( "The move is not finished.\n" . $state['problems'] . "\nRun `wp podcast import --resume` to copy them again, or `wp podcast finish-move` to finish the move and keep them at the old host." );
		}
	}

	/**
	 * Print what still loads from the old host, per kind.
	 *
	 * @param array<string, mixed> $state Client state.
	 * @return void
	 */
	private static function print_remaining( array $state ): void {
		foreach ( (array) ( $state['remaining'] ?? [] ) as $group ) {
			\WP_CLI::warning( 'Still at the old host: ' . $group['label'] . ':' );
			foreach ( (array) $group['episodes'] as $episode ) {
				\WP_CLI::log( sprintf( '  - %s: %s (%s)%s', $episode['title'], $episode['url'], rtrim( (string) $episode['reason'], '.' ), '' !== $episode['edit'] ? ' ' . $episode['edit'] : '' ) );
			}
			$more = (int) $group['count'] - count( (array) $group['episodes'] );
			if ( $more > 0 ) {
				\WP_CLI::log( sprintf( '  … and %d more', $more ) );
			}
		}
	}

	/**
	 * Cancel the import (also a check that waits to be imported, or a move
	 * that was not finished). Episodes already imported stay.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$job = ImportJob::get();
		if ( ! in_array( $job['status'] ?? '', [ 'loading', 'ready', 'running', 'waiting', 'done_with_problems' ], true ) ) {
			\WP_CLI::error( 'There is no import to cancel.' );
		}

		$state = ImportJob::cancel();
		if ( 'cancelled' !== $state['status'] ) {
			\WP_CLI::error( 'The import could not be cancelled: ' . $state['status'] );
		}

		\WP_CLI::success( sprintf( 'The import was cancelled (%d of %d episodes done). Episodes already imported stay.', (int) $state['done'], (int) $state['total'] ) );
	}

	/**
	 * Finish a move whose import left files at the old host: this site
	 * becomes the show's home although those files stay there. Lists them
	 * and asks first.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @subcommand finish-move
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function finish_move( array $args, array $assoc_args ): void {
		if ( ! ImportJob::move_unfinished() ) {
			\WP_CLI::error( 'There is no unfinished move.' );
		}

		$state = ImportJob::client_state( ImportJob::get() );
		self::print_remaining( $state );
		\WP_CLI::confirm( 'These stay at the old host and stop working when that account is closed. Finish the move anyway?', $assoc_args );

		$done = ImportJob::confirm_move();
		if ( is_wp_error( $done ) ) {
			\WP_CLI::error( $done->get_error_message() );
		}

		\WP_CLI::success( 'The move is finished: this website hosts the show now.' );
	}

	/**
	 * Sync episodes from the host's feed now (external hosting mode).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Ignore the conditional-request validators and read the whole feed.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function sync( array $args, array $assoc_args ): void {
		if ( ! Hosting::is_external() ) {
			\WP_CLI::error( 'This site hosts the podcast itself; there is no host feed to sync. Use `wp podcast import` instead.' );
		}

		$result = Hosting::sync( isset( $assoc_args['force'] ) );

		if ( 'ok' !== $result['status'] ) {
			\WP_CLI::error( $result['message'] );
		}

		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Show where the podcast is hosted, the last sync and the import.
	 *
	 * @return void
	 */
	public function status(): void {
		$state = Hosting::state();

		$rows = [
			[
				'key'   => 'hosting',
				'value' => Hosting::provider_name(),
			],
			[
				'key'   => 'feed for listeners',
				'value' => Hosting::public_feed_url(),
			],
			[
				'key'   => 'site feed',
				'value' => Feed::url() . ( '' !== Hosting::feed_redirect_target() ? ' (301 to host)' : '' ),
			],
			[
				'key'   => 'published episodes',
				'value' => (string) epm()->episodes->count_published(),
			],
		];

		if ( Hosting::is_external() ) {
			$rows[] = [
				'key'   => 'last sync',
				'value' => $state['last_run'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $state['last_run'] ) . ' UTC — ' . $state['message'] : 'never',
			];
			$next   = wp_next_scheduled( Hosting::CRON_HOOK );
			$rows[] = [
				'key'   => 'next sync',
				'value' => $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : 'off',
			];
		}

		$job = ImportJob::get();
		if ( ! empty( $job['status'] ) ) {
			$state  = ImportJob::client_state( $job );
			$rows[] = [
				'key'   => 'import',
				'value' => sprintf( '%s — %s (%d of %d episodes)', $state['status'], '' !== $state['title'] ? $state['title'] : $state['feed_url'], (int) $state['done'], (int) $state['total'] ),
			];
			if ( 'waiting' === $state['status'] ) {
				$rows[] = [
					'key'   => 'import waits until',
					'value' => gmdate( 'Y-m-d H:i:s', (int) $state['wait_until'] ) . ' UTC — ' . $state['wait_reason'],
				];
			}
			if ( is_array( $state['current'] ) ) {
				$rows[] = [
					'key'   => 'copying',
					'value' => sprintf( '%s (%s): %s of %s', $state['current']['title'], $state['current']['kind'], size_format( (int) $state['current']['bytes'] ), (int) $state['current']['total'] > 0 ? size_format( (int) $state['current']['total'] ) : '?' ),
				];
			}
			foreach ( (array) $state['remaining'] as $group ) {
				$rows[] = [
					'key'   => 'still at the old host',
					'value' => $group['label'] . ': ' . implode( ', ', array_column( (array) $group['episodes'], 'title' ) ) . ( (int) $group['count'] > count( (array) $group['episodes'] ) ? ' …' : '' ),
				];
			}
			if ( '' !== $state['error'] ) {
				$rows[] = [
					'key'   => 'import error',
					'value' => $state['error'],
				];
			}
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );

		$pending = Upgrade::pending();
		if ( ! empty( $pending ) ) {
			\WP_CLI::log( 'Upgrade work still queued: ' . implode( ', ', $pending ) . ' (`wp podcast upgrade` finishes it).' );
		}
	}

	/**
	 * Finish the upgrade work queued after a plugin update.
	 *
	 * The first request after an update only does quick work; work on
	 * every episode (durations in seconds, duplicate GUID rows) is done in
	 * batches by WP-Cron and admin page loads. This runs all of it now,
	 * batch by batch, without loading the whole catalog.
	 *
	 * ## EXAMPLES
	 *
	 *     wp podcast upgrade
	 *
	 * @return void
	 */
	public function upgrade(): void {
		// A version stored before this command (an update not yet seen by
		// any request) queues its work first.
		epm()->maybe_upgrade();

		$pending = Upgrade::pending();
		if ( empty( $pending ) ) {
			\WP_CLI::success( 'Nothing to do: the upgrade is complete.' );
			return;
		}

		\WP_CLI::log( 'Queued: ' . implode( ', ', $pending ) );
		$batches = 0;
		$removed = 0;
		do {
			$result   = Upgrade::run( 10.0 );
			$batches += $result['batches'];
			$removed += $result['removed_guids'];
			if ( $result['busy'] ) {
				\WP_CLI::log( 'Another request is working on it; waiting …' );
				sleep( 2 );
			} elseif ( 0 === $result['batches'] && ! empty( $result['pending'] ) ) {
				\WP_CLI::error( 'The upgrade made no progress (the database refused a write?). Pending: ' . implode( ', ', $result['pending'] ) );
			}
		} while ( ! empty( $result['pending'] ) );

		\WP_CLI::success( sprintf( 'Upgrade complete (%d batches; %d duplicate GUID rows removed).', $batches, $removed ) );
	}
}
