<?php
/**
 * Private storage for a feed import's parsed items (Layer 1).
 *
 * A preview reads a feed page by page and an import step needs only a few
 * items at a time, so the parsed catalog is stored in pieces:
 *
 * - item rows: up to CHUNK_BYTES of items as JSON;
 * - one index segment per feed page: for each new item its date, the item
 *   row it is in, its place there and a hash of its GUID (so later pages
 *   are checked for duplicates without loading earlier items);
 * - sorted index rows, built once the catalog is read: the import order
 *   (oldest first, undated last), ROW_ENTRIES entries per row.
 *
 * Everything lives in non-autoloaded rows of the options table named
 * epm_import_chunk_<key>_<kind><id>, where <key> is a job's storage key
 * (its creation time and a random part, never the job token) and <id> a
 * random number, so two requests never write the same row. Nothing is
 * stored as a file, so no web server can serve it. Rows are written and
 * read with $wpdb directly: they never enter an options cache.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImportStore {

	/**
	 * Option name prefix of every row.
	 */
	public const PREFIX = 'epm_import_chunk_';

	/**
	 * Largest item row (JSON). Far below max_allowed_packet (4 MB on
	 * MySQL 5.7, 16 MB on MariaDB, 64 MB on MySQL 8); one item larger than
	 * this gets a row of its own.
	 */
	private const CHUNK_BYTES = 512 * KB_IN_BYTES;

	/**
	 * Bytes per index entry: date (4), item row id (4), place in the row
	 * (2), GUID hash (10).
	 */
	private const ENTRY = 20;

	/**
	 * Entries per sorted index row (320 KB, 427 KB as base64).
	 */
	private const ROW_ENTRIES = 16384;

	/**
	 * Rows read in this request (the last few, by name).
	 *
	 * @var array<string, mixed>
	 */
	private static array $cache = [];

	/**
	 * A storage key for a new job.
	 *
	 * @return string "<time>_<random>"
	 */
	public static function new_key(): string {
		return time() . '_' . strtolower( wp_generate_password( 12, false ) );
	}

	/**
	 * The GUID hash used for duplicate checks.
	 *
	 * @param string $guid GUID.
	 * @return string 10 bytes.
	 */
	public static function hash( string $guid ): string {
		return substr( md5( $guid, true ), 0, 10 );
	}

	/**
	 * Store one feed page's new items.
	 *
	 * @param string                           $key    Storage key.
	 * @param array<int, array<string, mixed>> $items  Items (feed order, without duplicates).
	 * @param string[]                         $hashes GUID hashes, one per item.
	 * @return array{segment: string, rows: string[]}|\WP_Error The index segment id and every row name written.
	 */
	public static function put_page( string $key, array $items, array $hashes ) {
		$written = [];
		$entries = '';
		$batch   = [];
		$size    = 0;
		$index   = 0;

		$flush = static function () use ( $key, &$batch, &$size, &$written, &$entries ) {
			if ( empty( $batch ) ) {
				return true;
			}
			$id = self::insert( $key, 'i', (string) wp_json_encode( array_column( $batch, 'item' ) ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$written[] = self::name( $key, 'i', $id );
			foreach ( $batch as $offset => $entry ) {
				$entries .= pack( 'NNn', $entry['date'], hexdec( $id ), $offset ) . $entry['hash'];
			}
			$batch = [];
			$size  = 0;
			return true;
		};

		foreach ( $items as $item ) {
			$json = (string) wp_json_encode( $item );
			if ( ! empty( $batch ) && $size + strlen( $json ) > self::CHUNK_BYTES ) {
				$done = $flush();
				if ( is_wp_error( $done ) ) {
					self::delete( $written );
					return $done;
				}
			}
			$date    = (int) ( $item['pub_date'] ?? 0 );
			$batch[] = [
				'item' => $item,
				'date' => $date > 0 && $date < 4294967295 ? $date : 0,
				'hash' => (string) ( $hashes[ $index ] ?? self::hash( (string) ( $item['guid'] ?? '' ) ) ),
			];
			$size   += strlen( $json ) + 1;
			++$index;
		}
		$done = $flush();
		if ( is_wp_error( $done ) ) {
			self::delete( $written );
			return $done;
		}

		$segment = self::insert( $key, 'x', base64_encode( $entries ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary index in a text column.
		if ( is_wp_error( $segment ) ) {
			self::delete( $written );
			return $segment;
		}
		$written[] = self::name( $key, 'x', $segment );

		return [
			'segment' => $segment,
			'rows'    => $written,
		];
	}

	/**
	 * GUID hashes of every item stored so far (in index segments).
	 *
	 * @param string   $key      Storage key.
	 * @param string[] $segments Segment ids.
	 * @return array<string, true>|\WP_Error
	 */
	public static function hashes( string $key, array $segments ) {
		$seen = [];
		foreach ( $segments as $segment ) {
			$entries = self::read_index( $key, 'x', (string) $segment );
			if ( null === $entries ) {
				return self::missing();
			}
			for ( $at = 0, $len = strlen( $entries ); $at < $len; $at += self::ENTRY ) {
				$seen[ substr( $entries, $at + 10, 10 ) ] = true;
			}
		}

		return $seen;
	}

	/**
	 * Build the import order from the index segments: oldest first, undated
	 * last, feed order for equal dates.
	 *
	 * @param string   $key      Storage key.
	 * @param string[] $segments Segment ids, in page order.
	 * @return array{rows: string[], count: int}|\WP_Error Sorted row ids and the number of items.
	 */
	public static function sort( string $key, array $segments ) {
		$order = [];
		$all   = '';
		foreach ( $segments as $segment ) {
			$entries = self::read_index( $key, 'x', (string) $segment );
			if ( null === $entries ) {
				return self::missing();
			}
			$all .= $entries;
		}

		$count = intdiv( strlen( $all ), self::ENTRY );
		for ( $i = 0; $i < $count; $i++ ) {
			$date = (int) unpack( 'N', $all, $i * self::ENTRY )[1];
			// Undated items go last; the feed order decides among equals.
			$order[ sprintf( '%010d%09d', $date > 0 ? $date : 9999999999, $i ) ] = $i;
		}
		ksort( $order, SORT_STRING );

		$rows  = [];
		$chunk = '';
		$n     = 0;
		foreach ( $order as $i ) {
			$chunk .= substr( $all, $i * self::ENTRY, self::ENTRY );
			if ( ++$n === self::ROW_ENTRIES ) {
				$id = self::insert( $key, 's', base64_encode( $chunk ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary index in a text column.
				if ( is_wp_error( $id ) ) {
					self::delete( self::names( $key, 's', $rows ) );
					return $id;
				}
				$rows[] = $id;
				$chunk  = '';
				$n      = 0;
			}
		}
		if ( '' !== $chunk ) {
			$id = self::insert( $key, 's', base64_encode( $chunk ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary index in a text column.
			if ( is_wp_error( $id ) ) {
				self::delete( self::names( $key, 's', $rows ) );
				return $id;
			}
			$rows[] = $id;
		}

		return [
			'rows'  => $rows,
			'count' => $count,
		];
	}

	/**
	 * The item at a place in the import order (null when the stored data
	 * is gone). Reads one sorted index row and one item row; the last rows
	 * read are kept for the next item.
	 *
	 * @param string   $key      Storage key.
	 * @param string[] $sorted   Sorted row ids.
	 * @param int      $position Place (0-based).
	 * @return array<string, mixed>|null
	 */
	public static function item( string $key, array $sorted, int $position ): ?array {
		$row = $sorted[ intdiv( $position, self::ROW_ENTRIES ) ] ?? null;
		if ( null === $row || $position < 0 ) {
			return null;
		}

		$entries = self::read_index( $key, 's', (string) $row );
		$entry   = null === $entries ? '' : substr( $entries, ( $position % self::ROW_ENTRIES ) * self::ENTRY, self::ENTRY );
		if ( self::ENTRY !== strlen( $entry ) ) {
			return null;
		}

		$address = unpack( 'Ndate/Nrow/noffset', $entry );
		$items   = self::read_items( $key, sprintf( '%08x', $address['row'] ) );

		return is_array( $items[ $address['offset'] ] ?? null ) ? $items[ $address['offset'] ] : null;
	}

	/**
	 * Delete rows by name.
	 *
	 * @param string[] $names Option names.
	 * @return void
	 */
	public static function delete( array $names ): void {
		global $wpdb;

		foreach ( array_chunk( array_values( array_filter( $names ) ), 100 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $chunk ), '%s' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		}
		self::$cache = [];
	}

	/**
	 * Delete rows of a kind by id.
	 *
	 * @param string   $key  Storage key.
	 * @param string   $kind i, x or s.
	 * @param string[] $ids  Ids.
	 * @return void
	 */
	public static function delete_ids( string $key, string $kind, array $ids ): void {
		self::delete( self::names( $key, $kind, $ids ) );
	}

	/**
	 * Delete every row of a job.
	 *
	 * @param string $key Storage key.
	 * @return void
	 */
	public static function purge( string $key ): void {
		global $wpdb;

		if ( '' === $key ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX . $key . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.
		self::$cache = [];
	}

	/**
	 * Delete every row of every job.
	 *
	 * @return void
	 */
	public static function purge_all(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.
		self::$cache = [];
	}

	/**
	 * Storage keys that have rows, with the time each was created.
	 *
	 * @return array<string, int>
	 */
	public static function keys(): array {
		global $wpdb;

		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows.
		$keys  = [];
		foreach ( (array) $names as $name ) {
			if ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '(\d+)_([a-z0-9]+)_/', (string) $name, $m ) ) {
				$keys[ $m[1] . '_' . $m[2] ] = (int) $m[1];
			}
		}

		return $keys;
	}

	/**
	 * Option name of a row.
	 *
	 * @param string $key  Storage key.
	 * @param string $kind i, x or s.
	 * @param string $id   Row id.
	 * @return string
	 */
	private static function name( string $key, string $kind, string $id ): string {
		return self::PREFIX . $key . '_' . $kind . $id;
	}

	/**
	 * Option names of rows.
	 *
	 * @param string   $key  Storage key.
	 * @param string   $kind i, x or s.
	 * @param string[] $ids  Ids.
	 * @return string[]
	 */
	private static function names( string $key, string $kind, array $ids ): array {
		return array_map(
			static function ( $id ) use ( $key, $kind ) {
				return self::name( $key, $kind, (string) $id );
			},
			$ids
		);
	}

	/**
	 * Insert a row under a new random id.
	 *
	 * @param string $key   Storage key.
	 * @param string $kind  i, x or s.
	 * @param string $value Value (ASCII: JSON or base64).
	 * @return string|\WP_Error The id (8 hex digits).
	 */
	private static function insert( string $key, string $kind, string $value ) {
		global $wpdb;

		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';
		for ( $try = 0; $try < 5; $try++ ) {
			$id   = sprintf( '%08x', random_int( 1, 0xFFFFFFFF ) );
			$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::name( $key, $kind, $id ), $value, $autoload ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows, never cached.
			if ( 1 === (int) $rows ) {
				return $id;
			}
			if ( '' !== (string) $wpdb->last_error ) {
				break;
			}
		}

		return new \WP_Error(
			'epm_import_storage',
			sprintf(
				/* translators: %s: database error message */
				__( 'The import could not store the feed in the database: %s', 'elementor-podcast-manager' ),
				'' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'unknown error', 'elementor-podcast-manager' )
			)
		);
	}

	/**
	 * Read a row's value (null when it does not exist).
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private static function read( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private rows, never cached.

		return null === $value ? null : (string) $value;
	}

	/**
	 * An index row's entries (binary), kept for the next call.
	 *
	 * @param string $key  Storage key.
	 * @param string $kind x or s.
	 * @param string $id   Row id.
	 * @return string|null
	 */
	private static function read_index( string $key, string $kind, string $id ): ?string {
		$name = self::name( $key, $kind, $id );
		if ( ! array_key_exists( $name, self::$cache ) ) {
			$value = self::read( $name );
			self::remember( $name, null === $value ? null : (string) base64_decode( $value, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary index in a text column.
		}

		return self::$cache[ $name ];
	}

	/**
	 * An item row's items, kept for the next call.
	 *
	 * @param string $key Storage key.
	 * @param string $id  Row id.
	 * @return array<int, mixed>|null
	 */
	private static function read_items( string $key, string $id ): ?array {
		$name = self::name( $key, 'i', $id );
		if ( ! array_key_exists( $name, self::$cache ) ) {
			$value = self::read( $name );
			$items = null === $value ? null : json_decode( $value, true );
			self::remember( $name, is_array( $items ) ? $items : null );
		}

		return self::$cache[ $name ];
	}

	/**
	 * Keep a row for the next call; only the last few are kept, so memory
	 * does not grow with the catalog.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Decoded value.
	 * @return void
	 */
	private static function remember( string $name, $value ): void {
		if ( count( self::$cache ) >= 4 ) {
			array_shift( self::$cache );
		}
		self::$cache[ $name ] = $value;
	}

	/**
	 * The error for stored data that disappeared.
	 *
	 * @return \WP_Error
	 */
	private static function missing(): \WP_Error {
		return new \WP_Error( 'epm_import_missing', __( 'The stored feed data is missing. Check the feed again to restart the import.', 'elementor-podcast-manager' ) );
	}
}
