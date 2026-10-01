<?php
/**
 * Collects a feed document while it is built (Layer 1).
 *
 * The items are written as they are built; every CHUNK bytes become a
 * piece row of the feed cache (FeedStore), so a feed of thousands of
 * episodes never sits in memory. The channel part depends on the whole
 * set of episodes (newest date, trailers, build time), so it is written
 * last, as piece 0. Without a build name (the cache is turned off), or
 * when the database refuses a piece, the document is kept in memory.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FeedWriter {

	/**
	 * Largest piece. Far below max_allowed_packet (4 MB on MySQL 5.7,
	 * 16 MB on MariaDB) and memcached's 1 MB item limit.
	 */
	public const CHUNK = 256 * KB_IN_BYTES;

	/**
	 * Build name ('' while the document is kept in memory).
	 *
	 * @var string
	 */
	private string $gen;

	/**
	 * Text not yet stored.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Pieces stored so far (1 … n; 0 is the channel part).
	 *
	 * @var int
	 */
	private int $stored = 0;

	/**
	 * Running MD5 of the items part.
	 *
	 * @var \HashContext
	 */
	private $hash;

	/**
	 * Bytes of the items part.
	 *
	 * @var int
	 */
	private int $bytes = 0;

	/**
	 * Constructor.
	 *
	 * @param string $gen Build name, '' to keep the document in memory.
	 */
	public function __construct( string $gen = '' ) {
		$this->gen  = $gen;
		$this->hash = hash_init( 'md5' );
	}

	/**
	 * Build name ('' when the document is in memory).
	 *
	 * @return string
	 */
	public function gen(): string {
		return $this->gen;
	}

	/**
	 * Append text to the items part.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	public function write( string $text ): void {
		hash_update( $this->hash, $text );
		$this->bytes  += strlen( $text );
		$this->buffer .= $text;

		if ( '' !== $this->gen && strlen( $this->buffer ) >= self::CHUNK ) {
			$this->store();
		}
	}

	/**
	 * Finish the document with its channel part.
	 *
	 * @param string $head Channel part (everything before the first item).
	 * @return array{chunks: int, bytes: int, xml: string|null} xml is set when the document is in memory.
	 */
	public function finish( string $head ): array {
		if ( '' !== $this->gen && '' !== $this->buffer ) {
			$this->store();
		}
		if ( '' !== $this->gen && ! FeedStore::put( $this->gen, 0, $head ) ) {
			$this->to_memory();
		}

		if ( '' === $this->gen ) {
			return [
				'chunks' => 0,
				'bytes'  => strlen( $head ) + $this->bytes,
				'xml'    => $head . $this->buffer,
			];
		}

		return [
			'chunks' => $this->stored + 1,
			'bytes'  => strlen( $head ) + $this->bytes,
			'xml'    => null,
		];
	}

	/**
	 * MD5 of the items part.
	 *
	 * @return string
	 */
	public function items_hash(): string {
		return hash_final( hash_copy( $this->hash ) );
	}

	/**
	 * Store the buffer as the next piece.
	 *
	 * @return void
	 */
	private function store(): void {
		if ( FeedStore::put( $this->gen, $this->stored + 1, $this->buffer ) ) {
			++$this->stored;
			$this->buffer = '';
			return;
		}

		$this->to_memory();
	}

	/**
	 * The database refused a piece: keep the document in memory instead.
	 *
	 * @return void
	 */
	private function to_memory(): void {
		$text = '';
		for ( $n = 1; $n <= $this->stored; $n++ ) {
			$text .= (string) OptionRow::read( FeedStore::chunk_name( $this->gen, $n ) );
		}
		FeedStore::discard( $this->gen );

		$this->buffer = $text . $this->buffer;
		$this->stored = 0;
		$this->gen    = '';
	}
}
