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
	 * ## OPTIONS
	 *
	 * <feed>
	 * : RSS feed address, Apple Podcasts link or a page that links to the feed.
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
	 * ## EXAMPLES
	 *
	 *     wp podcast import https://anchor.fm/s/123abc/podcast/rss --move --copy-media --show-details --owner
	 *     wp podcast import https://podcasts.apple.com/us/podcast/example/id123456789 --draft
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$preview = ImportJob::preview( (string) $args[0] );
		if ( is_wp_error( $preview ) ) {
			\WP_CLI::error( $preview->get_error_message() );
		}

		\WP_CLI::log(
			sprintf(
				'%s — %d episodes (%d already here)%s',
				(string) ( $preview['channel']['title'] ?? $preview['feed_url'] ),
				(int) $preview['episodes'],
				(int) $preview['existing'],
				'' !== (string) $preview['provider_name'] ? ', ' . $preview['provider_name'] : ''
			)
		);

		$job = ImportJob::start(
			(string) $preview['token'],
			[
				'purpose'           => isset( $assoc_args['move'] ) ? 'move' : 'mirror',
				'status'            => isset( $assoc_args['draft'] ) ? 'draft' : 'publish',
				'download_media'    => isset( $assoc_args['copy-media'] ),
				'apply_channel'     => isset( $assoc_args['show-details'] ),
				'overwrite_channel' => false,
				'confirm_owner'     => isset( $assoc_args['owner'] ),
			]
		);
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing', max( 1, (int) $job['total'] ) );
		$done     = 0;

		while ( 'running' === $job['status'] ) {
			$job = ImportJob::step( 20.0 );
			if ( ! empty( $job['busy'] ) ) {
				sleep( 2 );
				continue;
			}
			$progress->tick( max( 0, (int) $job['done'] - $done ) );
			$done = (int) $job['done'];
		}
		$progress->finish();

		if ( 'done' !== $job['status'] ) {
			\WP_CLI::error( '' !== $job['error'] ? $job['error'] : 'The import stopped: ' . $job['status'] );
		}

		$counts = $job['counts'];
		\WP_CLI::success(
			sprintf(
				'%d new, %d updated, %d unchanged, %d skipped, %d failed.',
				(int) $counts['created'],
				(int) $counts['updated'],
				(int) $counts['unchanged'],
				(int) $counts['skipped'],
				(int) $counts['failed']
			)
		);
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
