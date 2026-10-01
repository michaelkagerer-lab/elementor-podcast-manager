<?php
/**
 * Options-table rows changed only with conditional statements (Layer 1).
 *
 * For values that several requests may write at the same time (the
 * upgrade lock and progress, the stored version, the feed cache): a row
 * is inserted only when it is missing, replaced only while it still holds
 * the value that was read, and deleted only while it holds a given value.
 * Reads and writes go to the database directly, past every options cache
 * (a persistent object cache or the "not an option" cache would otherwise
 * show a request an outdated value); the caches are cleared after a write
 * so get_option() reads the new value. The same technique protects the
 * import lock (ImportJob).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OptionRow {

	/**
	 * A row's value, read from the database (null when there is none).
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public static function read( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- never from a cache.

		return null === $value ? null : (string) $value;
	}

	/**
	 * Insert a row only when it does not exist.
	 *
	 * @param string $name     Option name.
	 * @param string $value    Value (stored as is: a string, not serialized).
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 * @return bool Whether this request inserted it.
	 */
	public static function insert( string $name, string $value, bool $autoload = false ): bool {
		global $wpdb;

		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, $autoload ? self::autoload() : self::no_autoload() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic test-and-set.
		self::forget( $name );

		return 1 === (int) $rows;
	}

	/**
	 * Replace a row's value only while it is $expected.
	 *
	 * @param string $name     Option name.
	 * @param string $expected Value read before.
	 * @param string $value    New value (must differ from $expected).
	 * @return bool Whether the row was replaced.
	 */
	public static function replace( string $name, string $expected, string $value ): bool {
		global $wpdb;

		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $value, $name, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap.
		self::forget( $name );

		return 1 === (int) $rows;
	}

	/**
	 * Delete a row; with $expected only while it holds that value.
	 *
	 * @param string      $name     Option name.
	 * @param string|null $expected Value it must hold (null: any).
	 * @return bool Whether a row was deleted.
	 */
	public static function delete( string $name, ?string $expected = null ): bool {
		global $wpdb;

		if ( null === $expected ) {
			$rows = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- private row.
		} else {
			$rows = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", $name, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-delete.
		}
		self::forget( $name );

		return (int) $rows > 0;
	}

	/**
	 * Take a lock row ("<time>:<owner>"). A lock older than $ttl seconds
	 * belongs to a request that died and is taken over, unless another
	 * request takes it over first.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Seconds after which a lock is abandoned.
	 * @return string The lock value this request now holds, '' when another request holds it.
	 */
	public static function lock( string $name, int $ttl ): string {
		$value = time() . ':' . wp_generate_password( 12, false );

		if ( self::insert( $name, $value ) ) {
			return $value;
		}

		$current = self::read( $name );
		if ( null === $current ) {
			// Released in the meantime.
			return self::insert( $name, $value ) ? $value : '';
		}

		$since = (int) strtok( $current, ':' );
		if ( $since > 0 && time() - $since <= $ttl ) {
			return '';
		}

		return self::replace( $name, $current, $value ) ? $value : '';
	}

	/**
	 * Renew a lock this request holds.
	 *
	 * @param string $name  Option name.
	 * @param string $value The value lock() returned (or the last renew()).
	 * @return string The new value, '' when the lock was taken over.
	 */
	public static function renew( string $name, string $value ): string {
		$owner = (string) substr( $value, (int) strpos( $value, ':' ) + 1 );
		$next  = time() . ':' . $owner;
		if ( $next === $value ) {
			return self::read( $name ) === $value ? $value : '';
		}

		return self::replace( $name, $value, $next ) ? $next : '';
	}

	/**
	 * Release a lock, if it is still the one this request holds.
	 *
	 * @param string $name  Option name.
	 * @param string $value Lock value.
	 * @return void
	 */
	public static function unlock( string $name, string $value ): void {
		if ( '' !== $value ) {
			self::delete( $name, $value );
		}
	}

	/**
	 * Drop an option from every options cache after a direct write, so the
	 * next get_option() reads the database.
	 *
	 * @param string $name Option.
	 * @return void
	 */
	public static function forget( string $name ): void {
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
	public static function no_autoload(): string {
		// WordPress 6.6 writes "off"; earlier versions only know "no".
		return function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';
	}

	/**
	 * The "autoloaded" value of the options table's autoload column.
	 *
	 * @return string
	 */
	private static function autoload(): string {
		return function_exists( 'wp_autoload_values_to_autoload' ) ? 'on' : 'yes';
	}
}
