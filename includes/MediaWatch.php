<?php
/**
 * Watches one media download while it streams (Layer 1, used by
 * MediaDownload).
 *
 * Attached to the cURL handle of the request through the `http_api_curl`
 * action, it is called by cURL at least once a second and stops the
 * transfer (by returning non-zero from the progress callback) when the file
 * gets too large, the disk cannot hold it or does not take the bytes, the
 * transfer is too slow, or the request's time is up. What it saw (HTTP
 * status, announced length, bytes received) stays readable afterwards,
 * also when the request ended with an error.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaWatch {

	/**
	 * Why the transfer was stopped ('' when it was not): too_large,
	 * disk_full, write_error, stalled, budget or http.
	 *
	 * @var string
	 */
	public string $stop = '';

	/**
	 * Details of the stop (the disk space message).
	 *
	 * @var string
	 */
	public string $detail = '';

	/**
	 * HTTP status of the response being received (0 before its headers).
	 *
	 * @var int
	 */
	public int $code = 0;

	/**
	 * Content-Length of that response (-1 when it sent none).
	 *
	 * @var int
	 */
	public int $length = -1;

	/**
	 * Body bytes of that response received so far.
	 *
	 * @var int
	 */
	public int $received = 0;

	/**
	 * Whether writing to the file failed (set by MediaDownload while it
	 * catches the transport's write notices).
	 *
	 * @var bool
	 */
	public bool $write_failed = false;

	/**
	 * Bytes received at the last disk check.
	 *
	 * @var int
	 */
	private int $checked_bytes = 0;

	/**
	 * Watched handle.
	 *
	 * @var \CurlHandle|resource|null
	 */
	private $handle = null;

	/**
	 * When the request started.
	 *
	 * @var float
	 */
	private float $started;

	/**
	 * Start of the current low-speed window.
	 *
	 * @var float
	 */
	private float $window_start;

	/**
	 * Bytes received at the start of the window.
	 *
	 * @var int
	 */
	private int $window_bytes = 0;

	/**
	 * Last disk write check.
	 *
	 * @var float
	 */
	private float $checked = 0.0;

	/**
	 * Whether the free space was checked against the announced length.
	 *
	 * @var bool
	 */
	private bool $space_checked = false;

	/**
	 * Constructor.
	 *
	 * @param float                           $deadline microtime() at which the transfer stops.
	 * @param int                             $cap      Largest file.
	 * @param int                             $offset   Bytes already downloaded (a Range request continues there).
	 * @param string                          $target   File the request writes.
	 * @param array{bytes: int, seconds: int} $slow     Low-speed limit.
	 * @param int                             $expected Expected file size checked before (0: unknown).
	 */
	public function __construct(
		private float $deadline,
		private int $cap,
		private int $offset,
		private string $target,
		private array $slow,
		private int $expected
	) {
		$this->started      = microtime( true );
		$this->window_start = $this->started;
	}

	/**
	 * `http_api_curl` action: watch this request's transfer.
	 *
	 * @param \CurlHandle|resource $handle cURL handle.
	 * @return void
	 */
	public function attach( $handle ): void {
		$this->handle = $handle;
		curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
		// PHP 8.2 has the newer name; both call back with the same arguments.
		curl_setopt( $handle, defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION, [ $this, 'progress' ] );
	}

	/**
	 * Whether this request received less than the low-speed limit on
	 * average (a request that ran out of time was a stall, not progress).
	 *
	 * @return bool
	 */
	public function too_slow(): bool {
		$elapsed = microtime( true ) - $this->started;

		return $elapsed >= 1.0 && $this->received < $this->slow['bytes'] * $elapsed;
	}

	/**
	 * Let go of the handle (its callback refers to this object), so a
	 * stopped transfer's connection is closed now, not when PHP collects
	 * the cycle.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( null !== $this->handle ) {
			curl_setopt( $this->handle, CURLOPT_NOPROGRESS, true );
			curl_setopt( $this->handle, defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION, null );
			$this->handle = null;
		}
	}

	/**
	 * cURL progress callback.
	 *
	 * @param \CurlHandle|resource $handle   cURL handle.
	 * @param int|float            $dl_total Bytes expected (0: unknown).
	 * @param int|float            $dl_now   Bytes received.
	 * @return int 0 to go on, 1 to stop the transfer.
	 */
	public function progress( $handle, $dl_total = 0, $dl_now = 0 ): int {
		$now  = microtime( true );
		$code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

		// A redirect: the next request (a new handle) is watched as well.
		if ( $code >= 300 && $code < 400 ) {
			return 0;
		}

		if ( $code !== $this->code ) {
			$this->code         = $code;
			$this->length       = -1;
			$this->window_start = $now;
			$this->window_bytes = 0;
			if ( $code > 0 ) {
				$length       = curl_getinfo( $handle, defined( 'CURLINFO_CONTENT_LENGTH_DOWNLOAD_T' ) ? CURLINFO_CONTENT_LENGTH_DOWNLOAD_T : CURLINFO_CONTENT_LENGTH_DOWNLOAD );
				$this->length = is_numeric( $length ) && $length >= 0 ? (int) $length : -1;
			}
		}
		$this->received = (int) $dl_now;

		if ( $code >= 400 ) {
			// An error page: its status is all that counts.
			return $this->received > MB_IN_BYTES ? $this->halt( 'http' ) : $this->time( $now );
		}

		if ( 200 === $code || 206 === $code ) {
			$base = 206 === $code ? $this->offset : 0;

			if ( ( $this->length > 0 && $base + $this->length > $this->cap ) || $base + $this->received > $this->cap ) {
				return $this->halt( 'too_large' );
			}

			// The host announced the size: check the disk once against it.
			if ( ! $this->space_checked && $this->length > 0 ) {
				$this->space_checked = true;
				if ( $base + $this->length > $this->expected ) {
					$short = MediaDownload::space_problem( $base + $this->length, $this->offset, $this->target );
					if ( '' !== $short ) {
						$this->detail = $short;
						return $this->halt( 'disk_full' );
					}
				}
			}

			// Every byte that arrived must be on the disk.
			if ( $this->write_failed ) {
				return $this->halt( 'write_error' );
			}
			if ( $now - $this->checked >= 1.0 || $this->received - $this->checked_bytes >= 256 * KB_IN_BYTES ) {
				$this->checked       = $now;
				$this->checked_bytes = $this->received;
				clearstatcache( true, $this->target );
				$written = is_file( $this->target ) ? (int) filesize( $this->target ) : 0;
				if ( $this->received - $written > 64 * KB_IN_BYTES ) {
					return $this->halt( 'write_error' );
				}
			}
		}

		return $this->time( $now );
	}

	/**
	 * Low-speed and time checks.
	 *
	 * @param float $now microtime().
	 * @return int
	 */
	private function time( float $now ): int {
		if ( $now - $this->window_start >= $this->slow['seconds'] ) {
			if ( $this->received - $this->window_bytes < $this->slow['bytes'] * $this->slow['seconds'] ) {
				return $this->halt( 'stalled' );
			}
			$this->window_start = $now;
			$this->window_bytes = $this->received;
		}

		return $now >= $this->deadline ? $this->halt( 'budget' ) : 0;
	}

	/**
	 * Stop the transfer.
	 *
	 * @param string $why Reason.
	 * @return int 1.
	 */
	private function halt( string $why ): int {
		if ( '' === $this->stop ) {
			$this->stop = $why;
		}

		return 1;
	}
}
