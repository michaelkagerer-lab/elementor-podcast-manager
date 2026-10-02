<?php
/**
 * Two-process race harness: helpers shared by the scenarios in race.php.
 *
 * Every scenario runs its roles (A, B, sometimes a monitor M) as separate
 * `wp eval-file` processes started at the same time by run.sh. They meet at
 * barriers: files in a directory shared by the run (EPM_RACE_DIR). A role
 * waits for a barrier file, or until every other role has finished, so a
 * barrier the fixed code never reaches cannot hang a run. Barriers sit on
 * observable behavior (a statement that touches the lock row, an episode
 * insert, a feed request), not on the code under test, so the same
 * scenarios run against old and new implementations.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'epm_race_dir' ) ) {

	/**
	 * Barrier directory of this run (with a trailing slash).
	 *
	 * @return string
	 */
	function epm_race_dir(): string {
		$dir = (string) getenv( 'EPM_RACE_DIR' );
		if ( '' === $dir ) {
			$dir = sys_get_temp_dir() . '/epm-race';
		}
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		return rtrim( $dir, '/' ) . '/';
	}

	/**
	 * This process's role (A, B, M) or the phase (setup, check).
	 *
	 * @return string
	 */
	function epm_race_role(): string {
		return (string) ( getenv( 'EPM_RACE_ROLE' ) ?: 'setup' );
	}

	/**
	 * The other roles running in this scenario.
	 *
	 * @return string[]
	 */
	function epm_race_peers(): array {
		$roles = array_filter( array_map( 'trim', explode( ',', (string) getenv( 'EPM_RACE_ROLES' ) ) ) );

		return array_values( array_diff( $roles, [ epm_race_role() ] ) );
	}

	/**
	 * Append a line to the run's event log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	function epm_race_log( string $message ): void {
		$line = sprintf( "%.4f [%s] %s\n", microtime( true ), epm_race_role(), $message );
		file_put_contents( epm_race_dir() . 'events.log', $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Raise a barrier flag.
	 *
	 * @param string $name Flag.
	 * @return void
	 */
	function epm_race_signal( string $name ): void {
		touch( epm_race_dir() . $name );
		epm_race_log( 'signal ' . $name );
	}

	/**
	 * Whether a flag is raised.
	 *
	 * @param string $name Flag.
	 * @return bool
	 */
	function epm_race_raised( string $name ): bool {
		clearstatcache( true, epm_race_dir() . $name );

		return file_exists( epm_race_dir() . $name );
	}

	/**
	 * Whether every other role has finished (or one of $roles, when given).
	 *
	 * @param string[] $roles Roles to check (default: all peers).
	 * @return bool
	 */
	function epm_race_peers_done( array $roles = [] ): bool {
		$roles = empty( $roles ) ? epm_race_peers() : $roles;
		foreach ( $roles as $role ) {
			if ( 'M' !== $role && ! epm_race_raised( 'done-' . $role ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Wait for a flag, or until the other roles have finished.
	 *
	 * @param string   $name    Flag.
	 * @param string[] $roles   Roles whose end also releases the wait (default: all peers).
	 * @param float    $timeout Seconds before the run counts as hung.
	 * @return bool Whether the flag was raised.
	 */
	function epm_race_wait( string $name, array $roles = [], float $timeout = 30.0 ): bool {
		$end = microtime( true ) + $timeout;
		epm_race_log( 'wait ' . $name );
		while ( ! epm_race_raised( $name ) ) {
			if ( epm_race_peers_done( $roles ) ) {
				epm_race_log( 'released ' . $name . ' (the other role finished)' );
				return false;
			}
			if ( microtime( true ) > $end ) {
				epm_race_log( 'TIMEOUT ' . $name );
				epm_race_result( [ 'timeouts' => array_merge( epm_race_results( epm_race_role() )['timeouts'] ?? [], [ $name ] ) ] );
				return false;
			}
			usleep( 2000 );
		}
		epm_race_log( 'passed ' . $name );

		return true;
	}

	/**
	 * Merge values into this role's result file.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return void
	 */
	function epm_race_result( array $values ): void {
		$file = epm_race_dir() . epm_race_role() . '.json';
		$data = epm_race_results( epm_race_role() );
		file_put_contents( $file, (string) wp_json_encode( array_merge( $data, $values ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * A role's results.
	 *
	 * @param string $role Role.
	 * @return array<string, mixed>
	 */
	function epm_race_results( string $role ): array {
		$file = epm_race_dir() . $role . '.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Run a callback before statements that touch the import lock row.
	 *
	 * The callback receives the SQL verb (SELECT, INSERT, UPDATE, DELETE,
	 * REPLACE) and the number of lock statements seen so far (1-based).
	 * Old and new lock code both reach the row through $wpdb, so this is
	 * the place both can be paused at.
	 *
	 * @param callable $callback Callback( string $verb, int $count ).
	 * @return void
	 */
	function epm_race_on_lock_statement( callable $callback ): void {
		$count = 0;
		add_filter(
			'query',
			static function ( $query ) use ( &$count, $callback ) {
				if ( false === strpos( (string) $query, 'epm_import_lock' ) ) {
					return $query;
				}
				if ( ! preg_match( '/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $query, $m ) ) {
					return $query;
				}
				++$count;
				$callback( strtoupper( $m[1] ), $count );

				return $query;
			}
		);
	}

	/**
	 * The lock row, read past every cache ('' when there is none).
	 *
	 * @return string
	 */
	function epm_race_lock_row(): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'epm_import_lock' ) );
	}

	/**
	 * The import job, read past every cache.
	 *
	 * @return array<string, mixed>
	 */
	function epm_race_job(): array {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", \EPM\ImportJob::OPTION ) );
		$job   = is_string( $value ) ? maybe_unserialize( $value ) : [];

		return is_array( $job ) ? $job : [];
	}

	/**
	 * One feed item.
	 *
	 * @param string $guid GUID.
	 * @param int    $time Publish time.
	 * @return string
	 */
	function epm_race_item( string $guid, int $time ): string {
		return '<item><title>Episode ' . esc_html( $guid ) . '</title><guid isPermaLink="false">' . esc_html( $guid ) . '</guid>'
			. '<pubDate>' . gmdate( 'D, d M Y H:i:s', $time ) . ' +0000</pubDate>'
			. '<description><![CDATA[<p>Notes for ' . esc_html( $guid ) . '</p>]]></description>'
			. '<enclosure url="https://feeds.example.test/media/' . rawurlencode( $guid ) . '.mp3" length="4000" type="audio/mpeg"/>'
			. '<itunes:duration>00:05</itunes:duration></item>';
	}

	/**
	 * A feed of $count items with the GUIDs <prefix>001 … (newest first).
	 *
	 * @param string $title  Show title.
	 * @param string $prefix GUID prefix.
	 * @param int    $count  Items.
	 * @return string
	 */
	function epm_race_feed( string $title, string $prefix, int $count ): string {
		$items = '';
		for ( $k = $count; $k >= 1; $k-- ) {
			$items .= epm_race_item( sprintf( '%s%03d', $prefix, $k ), strtotime( '2025-01-01 00:00:00 UTC' ) + $k * DAY_IN_SECONDS );
		}

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
			. '<title>' . esc_html( $title ) . '</title><link>https://show.example.test/</link><description>Race show</description><language>en</language>'
			. '<itunes:author>Race</itunes:author>'
			. $items . '</channel></rss>';
	}

	/**
	 * Serve a feed at a URL in this process.
	 *
	 * @param string          $url      URL.
	 * @param string          $body     Feed.
	 * @param callable|null   $before   Called before answering (a barrier).
	 * @return void
	 */
	function epm_race_route( string $url, string $body, ?callable $before = null ): void {
		EPM_Test_HTTP::$routes[ $url ] = static function () use ( $body, $before ) {
			if ( null !== $before ) {
				$before();
			}
			return EPM_Test_HTTP::response( 200, $body, [ 'content-type' => 'application/rss+xml' ] );
		};
	}

	/**
	 * Episodes per GUID for a prefix (any status), duplicates included.
	 *
	 * @param string $prefix GUID prefix.
	 * @return array<string, int>
	 */
	function epm_race_guid_counts( string $prefix ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS guid, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_epm_guid' AND pm.meta_value LIKE %s AND p.post_type = 'podcast_episode' GROUP BY pm.meta_value",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->guid ] = (int) $row->n;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Delete the episodes of a GUID prefix and reset the import state.
	 *
	 * @param string $prefix GUID prefix.
	 * @return void
	 */
	function epm_race_reset( string $prefix = 'race-' ): void {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epm_guid' AND meta_value LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( is_callable( [ \EPM\ImportJob::class, 'cancel' ] ) ) {
			\EPM\ImportJob::cancel();
		}
		if ( is_callable( [ \EPM\ImportJob::class, 'purge' ] ) ) {
			\EPM\ImportJob::purge();
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", \EPM\ImportJob::OPTION, 'epm_import_lock' ) );
		foreach ( [ \EPM\ImportJob::OPTION, 'epm_import_lock', 'notoptions', 'alloptions' ] as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		wp_clear_scheduled_hook( \EPM\ImportJob::CRON_HOOK );
	}
}
