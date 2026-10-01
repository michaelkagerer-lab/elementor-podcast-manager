<?php
/**
 * Cache of the rendered podcast feed (Layer 1).
 *
 * The feed of a large show is several megabytes. It is stored in pieces:
 *
 * - a pointer row (option epm_feed_cache, a few hundred bytes of JSON):
 *   the stored document's ETag, Last-Modified, expiry and the name and
 *   number of its pieces; or, while a request builds the feed, a marker
 *   naming that request's build;
 * - piece rows (options epm_feed_chunk_<build>_<n>): piece 0 is the
 *   channel part, pieces 1 … n the items and the closing tags, each at
 *   most FeedWriter::CHUNK bytes.
 *
 * Nothing is stored as a file, so no web server can serve it, and no row
 * is larger than a piece (no multi-megabyte option, no max_allowed_packet
 * or memcached item limit). A conditional request (304) reads only the
 * pointer; a full response streams the pieces one at a time.
 *
 * Every row is written and read with $wpdb directly and never enters an
 * options cache. The pointer changes only with conditional statements
 * (OptionRow): one request at a time builds the feed, and a build that a
 * change of the show overtook (the cache was flushed while it ran) is
 * served to its own request but never stored.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FeedStore {

	/**
	 * Pointer row.
	 */
	public const POINTER = 'epm_feed_cache';

	/**
	 * Name prefix of the piece rows.
	 */
	public const PREFIX = 'epm_feed_chunk_';

	/**
	 * Lifetime of a stored feed: a safety net for a change no hook saw.
	 */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Seconds after which a build marker counts as abandoned (the request
	 * that set it died).
	 */
	private const BUILD_TIMEOUT = 120;

	/**
	 * Format of the pointer.
	 */
	private const FORMAT = 2;

	/**
	 * The stored feed, null when there is none or it expired.
	 *
	 * @return array{gen: string, chunks: int, etag: string, modified: int, expires: int, bytes: int}|null
	 */
	public static function current(): ?array {
		$data = self::decode( OptionRow::read( self::POINTER ) );

		if ( null === $data || empty( $data['gen'] ) || (int) ( $data['expires'] ?? 0 ) <= time() ) {
			return null;
		}

		return [
			'gen'      => (string) $data['gen'],
			'chunks'   => (int) ( $data['chunks'] ?? 0 ),
			'etag'     => (string) ( $data['etag'] ?? '' ),
			'modified' => (int) ( $data['modified'] ?? 0 ),
			'expires'  => (int) $data['expires'],
			'bytes'    => (int) ( $data['bytes'] ?? 0 ),
		];
	}

	/**
	 * Claim the next build: only one request builds at a time.
	 *
	 * @return array{gen: string, marker: string}|null Null when another request builds (or a feed was stored meanwhile).
	 */
	public static function claim(): ?array {
		$gen    = self::new_gen();
		$marker = (string) wp_json_encode(
			[
				'v'        => self::FORMAT,
				'building' => $gen,
				'since'    => time(),
			]
		);
		$claim  = [
			'gen'    => $gen,
			'marker' => $marker,
		];

		if ( OptionRow::insert( self::POINTER, $marker ) ) {
			return $claim;
		}

		$current = OptionRow::read( self::POINTER );
		if ( null === $current ) {
			return OptionRow::insert( self::POINTER, $marker ) ? $claim : null;
		}

		$data = self::decode( $current );
		if ( null !== $data && ! empty( $data['gen'] ) && (int) ( $data['expires'] ?? 0 ) > time() ) {
			return null;
		}
		if ( null !== $data && ! empty( $data['building'] ) && time() - (int) ( $data['since'] ?? 0 ) <= self::BUILD_TIMEOUT ) {
			return null;
		}

		// Expired, abandoned or unreadable: take it over, unless another
		// request just did.
		return OptionRow::replace( self::POINTER, $current, $marker ) ? $claim : null;
	}

	/**
	 * A name for a new build: its start time and a random part.
	 *
	 * @return string
	 */
	public static function new_gen(): string {
		return time() . '_' . strtolower( wp_generate_password( 8, false ) );
	}

	/**
	 * Store a finished build, unless the cache was flushed since it was
	 * claimed (the show changed while it was built).
	 *
	 * @param array{gen: string, marker: string} $claim Claim.
	 * @param array<string, mixed>              $meta  chunks, etag, modified, bytes.
	 * @return bool Whether it is now the stored feed.
	 */
	public static function publish( array $claim, array $meta ): bool {
		$pointer = (string) wp_json_encode(
			[
				'v'        => self::FORMAT,
				'gen'      => $claim['gen'],
				'chunks'   => (int) $meta['chunks'],
				'etag'     => (string) $meta['etag'],
				'modified' => (int) $meta['modified'],
				'expires'  => time() + self::TTL,
				'bytes'    => (int) $meta['bytes'],
			]
		);

		if ( ! OptionRow::replace( self::POINTER, $claim['marker'], $pointer ) ) {
			return false;
		}

		self::sweep( $claim['gen'] );

		return true;
	}

	/**
	 * Give a claim back without storing anything (the build failed).
	 *
	 * @param array{gen: string, marker: string} $claim Claim.
	 * @return void
	 */
	public static function release( array $claim ): void {
		OptionRow::delete( self::POINTER, $claim['marker'] );
	}

	/**
	 * Forget the stored feed (the show changed). Its pieces stay for a
	 * moment, for requests still sending them, and are removed by a later
	 * build.
	 *
	 * @return void
	 */
	public static function flush(): void {
		OptionRow::delete( self::POINTER );
	}

	/**
	 * Name of a piece row.
	 *
	 * @param string $gen Build.
	 * @param int    $n   Piece.
	 * @return string
	 */
	public static function chunk_name( string $gen, int $n ): string {
		return self::PREFIX . $gen . '_' . $n;
	}

	/**
	 * Store a piece.
	 *
	 * @param string $gen   Build.
	 * @param int    $n     Piece.
	 * @param string $value Text.
	 * @return bool
	 */
	public static function put( string $gen, int $n, string $value ): bool {
		return OptionRow::insert( self::chunk_name( $gen, $n ), $value );
	}

	/**
	 * Whether every piece of a stored feed is still there.
	 *
	 * @param array<string, mixed> $meta Stored feed (current()).
	 * @return bool
	 */
	public static function complete( array $meta ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX . $meta['gen'] . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.

		return $count === (int) $meta['chunks'] && $count > 0;
	}

	/**
	 * Pass the pieces of a stored feed, in order, to a callback (one piece
	 * in memory at a time).
	 *
	 * @param array<string, mixed> $meta Stored feed.
	 * @param callable             $fn   Receives each piece; returning false stops.
	 * @return bool False when a piece is missing.
	 */
	public static function each( array $meta, callable $fn ): bool {
		for ( $n = 0; $n < (int) $meta['chunks']; $n++ ) {
			$piece = OptionRow::read( self::chunk_name( (string) $meta['gen'], $n ) );
			if ( null === $piece ) {
				return false;
			}
			if ( false === $fn( $piece ) ) {
				break;
			}
			unset( $piece );
		}

		return true;
	}

	/**
	 * Delete the pieces of one build.
	 *
	 * @param string $gen Build.
	 * @return void
	 */
	public static function discard( string $gen ): void {
		global $wpdb;

		if ( '' === $gen ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX . $gen . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.
	}

	/**
	 * Remove the pieces of older builds: everything but $keep and the two
	 * newest other builds (requests may still be sending those), and
	 * nothing younger than five minutes (a build still running).
	 *
	 * @param string $keep Build to keep.
	 * @return void
	 */
	public static function sweep( string $keep ): void {
		global $wpdb;

		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.
		$gens  = [];
		foreach ( (array) $names as $name ) {
			if ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '((\d+)_[a-z0-9]+)_\d+$/', (string) $name, $m ) && $m[1] !== $keep ) {
				$gens[ $m[1] ] = (int) $m[2];
			}
		}
		arsort( $gens );

		foreach ( array_slice( $gens, 2, null, true ) as $gen => $created ) {
			if ( time() - $created > 5 * MINUTE_IN_SECONDS ) {
				self::discard( (string) $gen );
			}
		}
	}

	/**
	 * Delete the stored feed and every piece (uninstall, tests).
	 *
	 * @return void
	 */
	public static function purge(): void {
		global $wpdb;

		OptionRow::delete( self::POINTER );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.
	}

	/**
	 * Decode a pointer row.
	 *
	 * @param string|null $value Row value.
	 * @return array<string, mixed>|null
	 */
	private static function decode( ?string $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$data = json_decode( $value, true );

		return is_array( $data ) && self::FORMAT === (int) ( $data['v'] ?? 0 ) ? $data : null;
	}
}
