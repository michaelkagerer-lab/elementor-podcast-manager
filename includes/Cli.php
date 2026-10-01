<?php
/**
 * WP-CLI commands: `wp podcast import|sync|status`.
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
	 * : Download audio and episode images into the Media Library.
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
	 * : Continue the last import: read the rest of a feed that could not be read completely, or keep an interrupted import going.
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

		// An import that was interrupted (or is waiting for WP-Cron).
		if ( $resume && 'running' === ( $job['status'] ?? '' ) ) {
			$state = ImportJob::client_state( $job );
			\WP_CLI::log( sprintf( 'Continuing the import of %s (%d of %d done).', '' !== $state['title'] ? $state['title'] : $state['feed_url'], (int) $state['done'], (int) $state['total'] ) );
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

		while ( 'running' === $state['status'] ) {
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
	 * Report a finished import (an error when it did not finish).
	 *
	 * @param array<string, mixed> $state Final client state.
	 * @return void
	 */
	private static function report( array $state ): void {
		if ( 'done' !== $state['status'] ) {
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

		// Never a plain success for part of a catalog.
		$catalog = $state['catalog'] ?? null;
		if ( is_array( $catalog ) && empty( $catalog['complete'] ) ) {
			\WP_CLI::warning( 'Imported from an incomplete feed: ' . $catalog['message'] );
			\WP_CLI::log( 'Imported: ' . $line );
		} else {
			\WP_CLI::success( $line );
		}

		// Audio that stayed at the old host must be copied before that
		// account is closed.
		if ( (int) ( $counts['media_failed'] ?? 0 ) > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'The audio of %d episode(s) was not copied and still loads from the old host: %s',
					(int) $counts['media_failed'],
					implode(
						', ',
						array_map(
							static function ( $episode ) {
								return (string) $episode['title'];
							},
							(array) ( $state['media_failed'] ?? [] )
						)
					)
				)
			);
		}
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
	 * Show where the podcast is hosted and the last sync.
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

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );
	}
}
