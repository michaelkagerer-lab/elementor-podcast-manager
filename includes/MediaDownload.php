<?php
/**
 * Streamed, bounded download of one media file (Layer 1).
 *
 * Used when a show moves here ("Copy audio"): audio files of several
 * hundred megabytes, episode images and transcript files. One call is one
 * HTTP request that never runs longer than the time it is given, so an
 * import step stays well below the 60 seconds proxies allow; a file that
 * does not arrive in one request is continued by the next one with an HTTP
 * Range request. The state of a download is a small array the import job
 * stores between requests.
 *
 * Requests go through wp_safe_remote_get(): addresses on private networks
 * are refused, also after redirects. While the file streams to disk the
 * request is watched (cURL progress callback) and stopped when
 *
 * - the file grows beyond the limit for its kind (also without a
 *   Content-Length: chunked or close-delimited responses),
 * - the server announces a file larger than the limit or larger than the
 *   free disk space,
 * - fewer bytes reach the disk than arrived (disk full),
 * - the transfer is slower than the low-speed limit,
 * - the time of this request is used up (the download continues later).
 *
 * After the request, the bytes on disk are compared with the Content-Length
 * the server sent, so a cut-off file is never taken for a complete one.
 * HTTP 429 (and 503 with Retry-After) asks the caller to wait instead of
 * failing.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaDownload {

	/**
	 * Prefix of every file this class writes to the temp folder.
	 */
	public const PREFIX = 'epm-media-';

	/**
	 * Largest file accepted per kind (filter epm_media_max_bytes).
	 *
	 * @param string $kind audio, image or transcript.
	 * @return int Bytes.
	 */
	public static function max_bytes( string $kind ): int {
		$defaults = [
			'audio'      => GB_IN_BYTES,
			'image'      => 20 * MB_IN_BYTES,
			'transcript' => 5 * MB_IN_BYTES,
		];

		/**
		 * Filter the largest media file an import copies.
		 *
		 * @param int    $bytes Limit in bytes (audio 1 GB, image 20 MB, transcript 5 MB).
		 * @param string $kind  audio, image or transcript.
		 */
		return max( 1, (int) apply_filters( 'epm_media_max_bytes', $defaults[ $kind ] ?? $defaults['image'], $kind ) );
	}

	/**
	 * The low-speed limit: a request that receives fewer than `bytes` per
	 * second over `seconds` seconds is stopped (filter epm_media_low_speed).
	 *
	 * @return array{bytes: int, seconds: int}
	 */
	public static function low_speed(): array {
		/**
		 * Filter the low-speed limit of media downloads.
		 *
		 * @param array{bytes: int, seconds: int} $limit Default: 1 KB per second over 15 seconds.
		 */
		$limit = (array) apply_filters(
			'epm_media_low_speed',
			[
				'bytes'   => KB_IN_BYTES,
				'seconds' => 15,
			]
		);

		return [
			'bytes'   => max( 1, (int) ( $limit['bytes'] ?? KB_IN_BYTES ) ),
			'seconds' => max( 1, (int) ( $limit['seconds'] ?? 15 ) ),
		];
	}

	/**
	 * Most seconds one request spends downloading (filter
	 * epm_media_request_seconds). An import step gives a download less when
	 * its own budget is smaller.
	 *
	 * @return float
	 */
	public static function request_seconds(): float {
		/**
		 * Filter how long one request may spend on a media download.
		 *
		 * @param float $seconds Default 20; at most 50 (proxies stop requests after 60).
		 */
		return min( 50.0, max( 1.0, (float) apply_filters( 'epm_media_request_seconds', 20.0 ) ) );
	}

	/**
	 * Failed attempts (errors, stalls, cut-off transfers, interrupted
	 * requests) after which a file counts as not copyable in this run.
	 *
	 * @return int
	 */
	public static function max_attempts(): int {
		/**
		 * Filter how often a failing media download is tried in one import.
		 *
		 * @param int $attempts Default 3.
		 */
		return max( 1, (int) apply_filters( 'epm_media_max_attempts', 3 ) );
	}

	/**
	 * How often an import waits for a host that asks it to (HTTP 429/503)
	 * before the file counts as not copyable in this run.
	 *
	 * @return int
	 */
	public static function max_waits(): int {
		/**
		 * Filter how often an import waits for a host that asks it to.
		 *
		 * @param int $waits Default 5.
		 */
		return max( 0, (int) apply_filters( 'epm_media_max_waits', 5 ) );
	}

	/**
	 * Longest wait a host can ask for (Retry-After is capped to this).
	 *
	 * @return int Seconds.
	 */
	private static function max_wait_seconds(): int {
		/**
		 * Filter the longest wait an import accepts from a host.
		 *
		 * @param int $seconds Default six hours.
		 */
		return max( 1, (int) apply_filters( 'epm_media_max_wait', 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Free space kept on a disk on top of a file (5 %, at least 16 MB).
	 *
	 * @param int $bytes File size.
	 * @return int
	 */
	public static function margin( int $bytes ): int {
		return max( 16 * MB_IN_BYTES, (int) ( $bytes / 20 ) );
	}

	/**
	 * A new download.
	 *
	 * @param string $url  Source URL.
	 * @param string $kind audio, image or transcript.
	 * @param int    $hint Size the feed announces (0: unknown).
	 * @return array<string, mixed>
	 */
	public static function fresh( string $url, string $kind, int $hint = 0 ): array {
		return [
			'url'      => $url,
			'kind'     => $kind,
			'hint'     => max( 0, $hint ),
			'file'     => '',
			'bytes'    => 0,
			'total'    => 0,
			'length'   => false,
			'type'     => '',
			'attempts' => 0,
			'waits'    => 0,
			'no_range' => false,
			'result'   => '',
			'reason'   => '',
			'message'  => '',
			'until'    => 0,
		];
	}

	/**
	 * One request of a download: start it, or continue where the last
	 * request stopped.
	 *
	 * The returned state's `result` is
	 * - complete: the whole file is in `file` (`bytes` long);
	 * - partial:  time ran out; call again with the returned state;
	 * - retry:    this attempt failed (counted in `attempts`), another may work;
	 * - wait:     the host asked to wait until `until`;
	 * - failed:   not copyable in this run (`reason`, `message`); files removed.
	 *
	 * @param array<string, mixed> $d        State from fresh() or an earlier call.
	 * @param float                $deadline microtime() at which this request must end.
	 * @return array<string, mixed>
	 */
	public static function run( array $d, float $deadline ): array {
		$d           = array_merge( self::fresh( (string) ( $d['url'] ?? '' ), (string) ( $d['kind'] ?? 'audio' ) ), $d );
		$d['result'] = '';
		$cap         = self::max_bytes( (string) $d['kind'] );

		// The partial file may be gone (temp folder cleaned): start over.
		if ( '' !== $d['file'] && ! is_file( $d['file'] ) ) {
			$d['file']  = '';
			$d['bytes'] = 0;
			$d['total'] = 0;
		}
		if ( '' === $d['file'] ) {
			$d['file']  = self::temp_path( (string) $d['url'] );
			$d['bytes'] = 0;
		} else {
			clearstatcache( true, $d['file'] );
			$d['bytes'] = (int) filesize( $d['file'] );
		}

		// Enough disk space for what is still to come (temp folder) and for
		// the whole file (uploads folder)?
		$expected = (int) $d['total'] > 0 ? (int) $d['total'] : ( (int) $d['hint'] <= $cap ? (int) $d['hint'] : 0 );
		$short    = self::space_problem( $expected, (int) $d['bytes'], (string) $d['file'] );
		if ( '' !== $short ) {
			return self::fail( $d, 'disk_full', $short );
		}

		$seconds = $deadline - microtime( true );
		if ( $seconds < 1.0 ) {
			$d['result'] = 'partial';
			return $d;
		}

		$resume = (int) $d['bytes'] > 0;
		$target = $resume ? $d['file'] . '.next' : $d['file'];
		self::remove( $target );

		$headers = [ 'Accept-Encoding' => 'identity' ];
		if ( $resume ) {
			$headers['Range'] = 'bytes=' . (int) $d['bytes'] . '-';
		}

		$watch = new MediaWatch( $deadline, $cap, (int) $d['bytes'], $target, self::low_speed(), $expected );
		$hook  = [ $watch, 'attach' ];
		add_action( 'http_api_curl', $hook, 10, 3 );
		// The transport writes the file and ignores failed writes (a full
		// disk): each one raises a notice. Catch those for this request and
		// let the watch stop the transfer instead.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function ( $level, $message ) use ( $watch ) {
				if ( 0 === strpos( (string) $message, 'fwrite(): Write of' ) ) {
					$watch->write_failed = true;
					return true;
				}
				return false;
			},
			E_NOTICE | E_WARNING
		);
		try {
			$response = SafeHttp::get(
				(string) $d['url'],
				[
					// The watch stops the transfer at the deadline; this is
					// only the transport's own limit behind it.
					'timeout'             => (float) ceil( $seconds + 5 ),
					'redirection'         => 5,
					'stream'              => true,
					'filename'            => $target,
					'headers'             => $headers,
					'decompress'          => false,
					// Without cURL (no watch), the transport stops reading
					// one byte past the limit.
					'limit_response_size' => $cap - (int) $d['bytes'] + 1,
					'user-agent'          => 'ElementorPodcastManager/' . EPM_VERSION . '; ' . home_url( '/' ),
				]
			);
		} finally {
			restore_error_handler();
			remove_action( 'http_api_curl', $hook, 10 );
			$watch->release();
			// A stopped transfer leaves the transport and its handle in a
			// reference cycle: collect it, which closes the connection.
			if ( '' !== $watch->stop ) {
				gc_collect_cycles();
			}
		}

		clearstatcache( true, $target );
		$written = is_file( $target ) ? (int) filesize( $target ) : 0;

		if ( is_wp_error( $response ) ) {
			return self::after_error( $d, $watch, $response, $target, $written, $resume );
		}

		return self::after_response( $d, $watch, $response, $target, $written, $resume, $cap );
	}

	/**
	 * A request that ended with an error (the watch stopped it, or the
	 * transport failed).
	 *
	 * @param array<string, mixed> $d        State.
	 * @param MediaWatch           $watch    Watch of the request.
	 * @param \WP_Error            $error    Error.
	 * @param string               $target   File the request wrote.
	 * @param int                  $written  Bytes in $target.
	 * @param bool                 $resume   Whether this was a Range request.
	 * @return array<string, mixed>
	 */
	private static function after_error( array $d, MediaWatch $watch, \WP_Error $error, string $target, int $written, bool $resume ): array {
		$data = in_array( $watch->code, [ 200, 206 ], true );

		switch ( $watch->stop ) {
			case 'too_large':
				return self::fail( $d, 'too_large', self::too_large_message( (string) $d['kind'] ) );

			case 'disk_full':
				return self::fail( $d, 'disk_full', $watch->detail );

			case 'write_error':
				return self::fail( $d, 'write_error', __( 'The file could not be written to the disk (it may be full).', 'elementor-podcast-manager' ) );

			case 'http':
				return self::fail( $d, 'http', self::http_message( $watch->code ) );

			case 'budget':
				if ( $data && $written > 0 && self::keep( $d, $watch, $target, $written, $resume ) ) {
					// Hardly anything arrived in all that time: a stall.
					if ( $watch->too_slow() ) {
						return self::attempt( $d, 'stalled', self::stalled_message() );
					}
					// A host without Range support sends the file from the
					// start every time: it never gets further than one
					// request's worth.
					if ( ! empty( $d['no_range'] ) ) {
						return self::attempt( $d, 'no_range', self::no_range_message() );
					}
					$d['result'] = 'partial';
					return $d;
				}
				self::drop( $d, $target, $resume );
				return self::attempt( $d, 'stalled', self::stalled_message() );

			case 'stalled':
				if ( ! ( $data && $written > 0 && self::keep( $d, $watch, $target, $written, $resume ) ) ) {
					self::drop( $d, $target, $resume );
				}
				return self::attempt( $d, 'stalled', self::stalled_message() );
		}

		// wp_safe_remote_get() refused the address or a redirect target
		// (wp_http_validate_url(); WordPress's own message, in the site's
		// language).
		if ( $error->get_error_message() === translate( 'A valid URL was not provided.', 'default' ) ) { // phpcs:ignore WordPress.WP.I18n -- WordPress core's message.
			return self::fail( $d, 'unsafe', self::unsafe_message() );
		}

		// A broken connection: what arrived is kept for the next attempt.
		if ( 'epm_insecure_redirect' === $error->get_error_code() ) {
			return self::fail( $d, 'unsafe', $error->get_error_message() );
		}
		if ( ! ( $data && $written > 0 && self::keep( $d, $watch, $target, $written, $resume ) ) ) {
			self::drop( $d, $target, $resume );
		}

		return self::attempt(
			$d,
			'transport',
			sprintf(
				/* translators: %s: error message of the connection, e.g. "cURL error 7: Failed to connect" */
				__( 'The download failed: %s', 'elementor-podcast-manager' ),
				rtrim( $error->get_error_message(), '.' ) . '.'
			)
		);
	}

	/**
	 * A request that returned a response.
	 *
	 * @param array<string, mixed> $d        State.
	 * @param MediaWatch           $watch    Watch of the request.
	 * @param array<string, mixed> $response Response.
	 * @param string               $target   File the request wrote.
	 * @param int                  $written  Bytes in $target.
	 * @param bool                 $resume   Whether this was a Range request.
	 * @param int                  $cap      Size limit.
	 * @return array<string, mixed>
	 */
	private static function after_response( array $d, MediaWatch $watch, array $response, string $target, int $written, bool $resume, int $cap ): array {
		$code = (int) wp_remote_retrieve_response_code( $response );

		// The host asks to come back later: wait, do not fail.
		$retry = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( 429 === $code || ( 503 === $code && '' !== $retry ) ) {
			// The answer is no part of the file (a Range part, or the whole
			// file of a first request).
			self::remove( $target );
			if ( ! $resume ) {
				$d['bytes'] = 0;
			}
			++$d['waits'];
			if ( (int) $d['waits'] > self::max_waits() ) {
				return self::fail( $d, 'rate_limited', self::http_message( $code ) . ' ' . __( 'It kept asking to wait.', 'elementor-podcast-manager' ) );
			}
			$d['result'] = 'wait';
			$d['until']  = time() + self::retry_after( $retry );
			$d['reason'] = 'rate_limited';
			$d['message'] = self::http_message( $code );
			return $d;
		}

		if ( 200 !== $code && 206 !== $code ) {
			return self::fail( $d, 'http', self::http_message( $code ) );
		}

		// Fewer bytes on disk than arrived: the disk is full.
		if ( $watch->write_failed || $watch->received > $written ) {
			return self::fail( $d, 'write_error', __( 'The file could not be written to the disk (it may be full).', 'elementor-podcast-manager' ) );
		}

		$length = wp_remote_retrieve_header( $response, 'content-length' );
		$length = is_numeric( $length ) ? (int) $length : -1;
		$range  = (string) wp_remote_retrieve_header( $response, 'content-range' );

		if ( $resume && 206 === $code ) {
			// Continued where the file ended?
			if ( ! preg_match( '#^bytes\s+(\d+)-\d+/(\d+|\*)#i', $range, $m ) || (int) $m[1] !== (int) $d['bytes'] ) {
				self::remove( $target );
				self::remove( (string) $d['file'] );
				$d['bytes'] = 0;
				$d['total'] = 0;
				return self::attempt( $d, 'transport', __( 'The host sent a different part of the file than requested.', 'elementor-podcast-manager' ) );
			}
			$part_ok = -1 === $length || $written === $length;
			if ( ! self::append( $target, (string) $d['file'] ) ) {
				return self::fail( $d, 'write_error', __( 'The file could not be written to the disk (it may be full).', 'elementor-podcast-manager' ) );
			}
			$d['bytes']  = (int) $d['bytes'] + $written;
			$d['total']  = is_numeric( $m[2] ) ? (int) $m[2] : ( -1 !== $length ? (int) $d['bytes'] + $length - $written : 0 );
			$d['length'] = (int) $d['total'] > 0;
			if ( ! $part_ok ) {
				return self::truncated( $d );
			}
		} else {
			if ( $resume ) {
				// The host ignored the Range request: this response is the
				// whole file from the start.
				$d['no_range'] = true;
				self::remove( (string) $d['file'] );
				if ( ! @rename( $target, (string) $d['file'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
					return self::fail( $d, 'write_error', __( 'The file could not be written to the disk (it may be full).', 'elementor-podcast-manager' ) );
				}
			}
			$d['bytes']  = $written;
			$d['total']  = -1 !== $length ? $length : 0;
			$d['length'] = -1 !== $length;
			if ( -1 !== $length && $written < $length ) {
				return self::truncated( $d );
			}
		}

		$d['type'] = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( (int) $d['bytes'] > $cap ) {
			return self::fail( $d, 'too_large', self::too_large_message( (string) $d['kind'] ) );
		}
		if ( (int) $d['total'] > 0 && (int) $d['bytes'] < (int) $d['total'] ) {
			return self::truncated( $d );
		}

		$d['result'] = 'complete';

		return $d;
	}

	/**
	 * Keep what a request received: a first request wrote the file itself,
	 * a Range request's part is appended (only when the host sent that
	 * part; anything else is dropped).
	 *
	 * @param array<string, mixed> $d       State (bytes, total, file updated).
	 * @param MediaWatch           $watch   Watch of the request.
	 * @param string               $target  File the request wrote.
	 * @param int                  $written Bytes in $target.
	 * @param bool                 $resume  Whether this was a Range request.
	 * @return bool Whether anything was kept.
	 */
	private static function keep( array &$d, MediaWatch $watch, string $target, int $written, bool $resume ): bool {
		if ( $watch->received > $written ) {
			// Arrived but not written: never trust that file.
			self::remove( $target );
			if ( ! $resume ) {
				$d['bytes'] = 0;
			}
			return false;
		}

		if ( ! $resume ) {
			$d['bytes'] = $written;
			if ( $watch->length > 0 && 200 === $watch->code ) {
				$d['total']  = $watch->length;
				$d['length'] = true;
			}
			return true;
		}

		if ( 206 === $watch->code ) {
			if ( ! self::append( $target, (string) $d['file'] ) ) {
				return false;
			}
			$d['bytes'] = (int) $d['bytes'] + $written;
			if ( $watch->length > 0 ) {
				$d['total']  = (int) $d['bytes'] - $written + $watch->length;
				$d['length'] = true;
			}
			return true;
		}

		// 200 to a Range request: the host sends the whole file again.
		$d['no_range'] = true;
		self::remove( (string) $d['file'] );
		if ( ! @rename( $target, (string) $d['file'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			$d['bytes'] = 0;
			return false;
		}
		$d['bytes'] = $written;
		if ( $watch->length > 0 ) {
			$d['total']  = $watch->length;
			$d['length'] = true;
		}

		return true;
	}

	/**
	 * Drop what a request wrote (it is no part of the file).
	 *
	 * @param array<string, mixed> $d      State (bytes reset for a first request).
	 * @param string               $target File the request wrote.
	 * @param bool                 $resume Whether this was a Range request.
	 * @return void
	 */
	private static function drop( array &$d, string $target, bool $resume ): void {
		self::remove( $target );
		if ( ! $resume ) {
			$d['bytes'] = 0;
			$d['total'] = 0;
		}
	}

	/**
	 * The download ended before the length the host announced.
	 *
	 * @param array<string, mixed> $d State.
	 * @return array<string, mixed>
	 */
	private static function truncated( array $d ): array {
		return self::attempt(
			$d,
			'truncated',
			sprintf(
				/* translators: 1: bytes received, e.g. "20 MB", 2: file size the host announced, e.g. "50 MB" */
				__( 'The download stopped after %1$s of %2$s.', 'elementor-podcast-manager' ),
				size_format( (int) $d['bytes'], 1 ),
				size_format( max( (int) $d['total'], (int) $d['bytes'] ), 1 )
			)
		);
	}

	/**
	 * Count a failed attempt: another one follows unless the limit is
	 * reached (then the file is not copyable in this run).
	 *
	 * @param array<string, mixed> $d       State.
	 * @param string               $reason  Reason code.
	 * @param string               $message Reason in words.
	 * @return array<string, mixed>
	 */
	private static function attempt( array $d, string $reason, string $message ): array {
		++$d['attempts'];
		$d['reason']  = $reason;
		$d['message'] = $message;

		if ( (int) $d['attempts'] >= self::max_attempts() ) {
			return self::fail( $d, $reason, $message );
		}

		$d['result'] = 'retry';

		return $d;
	}

	/**
	 * Give up on a download: files removed.
	 *
	 * @param array<string, mixed> $d       State.
	 * @param string               $reason  Reason code.
	 * @param string               $message Reason in words.
	 * @return array<string, mixed>
	 */
	private static function fail( array $d, string $reason, string $message ): array {
		self::discard( $d );
		$d['file']    = '';
		$d['bytes']   = 0;
		$d['result']  = 'failed';
		$d['reason']  = $reason;
		$d['message'] = $message;

		return $d;
	}

	/**
	 * Remove the files of a download (the partial file and a Range part).
	 *
	 * @param array<string, mixed> $d State.
	 * @return void
	 */
	public static function discard( array $d ): void {
		$file = (string) ( $d['file'] ?? '' );
		if ( '' !== $file ) {
			self::remove( $file );
			self::remove( $file . '.next' );
		}
	}

	/**
	 * Delete a file of this class (only files with its prefix in their name).
	 *
	 * @param string $file Path.
	 * @return void
	 */
	public static function remove( string $file ): void {
		if ( '' !== $file && 0 === strpos( basename( $file ), self::PREFIX ) && is_file( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Remove download files older than an hour that no running import
	 * uses (a request that died, a cancelled import).
	 *
	 * @param string[] $keep Paths still in use.
	 * @return int Files removed.
	 */
	public static function cleanup_stale( array $keep = [] ): int {
		$removed = 0;
		foreach ( (array) glob( get_temp_dir() . self::PREFIX . '*' ) as $file ) {
			$file = (string) $file;
			if ( in_array( $file, $keep, true ) || in_array( preg_replace( '/\.next$/', '', $file ), $keep, true ) ) {
				continue;
			}
			if ( is_file( $file ) && time() - (int) filemtime( $file ) > HOUR_IN_SECONDS ) {
				self::remove( $file );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * A new temp path for a download.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	private static function temp_path( string $url ): string {
		return get_temp_dir() . self::PREFIX . substr( md5( $url ), 0, 12 ) . '-' . strtolower( wp_generate_password( 8, false ) ) . '.part';
	}

	/**
	 * Append a file to another and remove it.
	 *
	 * @param string $part Part.
	 * @param string $file File to append to.
	 * @return bool Whether every byte was appended.
	 */
	private static function append( string $part, string $file ): bool {
		$size = (int) filesize( $part );
		$in   = @fopen( $part, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out  = @fopen( $file, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$done = false;
		if ( $in && $out ) {
			$copied = stream_copy_to_stream( $in, $out );
			$done   = false !== fflush( $out ) && $copied === $size;
		}
		if ( $in ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $out ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		self::remove( $part );

		return $done;
	}

	/**
	 * Why a file of this size does not fit on the disk ('' when it does,
	 * or when the free space cannot be read).
	 *
	 * @param int    $total Expected file size (0: unknown).
	 * @param int    $have  Bytes already downloaded.
	 * @param string $file  Partial file (its folder is checked).
	 * @return string
	 */
	public static function space_problem( int $total, int $have, string $file ): string {
		$uploads = wp_upload_dir( null, false );
		$checks  = [
			// Temp folder: the rest of the download.
			dirname( $file ) => max( 0, $total - $have ),
			// Uploads folder: the whole file.
			(string) ( $uploads['basedir'] ?? '' ) => $total,
		];

		foreach ( $checks as $dir => $need ) {
			if ( '' === $dir || ! is_dir( $dir ) || ! function_exists( 'disk_free_space' ) ) {
				continue;
			}
			$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.

			/**
			 * Filter the free disk space a media download counts on (bytes;
			 * false when unknown).
			 *
			 * @param float|false $free Free space reported by the system.
			 * @param string      $dir  Folder.
			 */
			$free = apply_filters( 'epm_media_disk_free', $free, $dir );
			if ( false === $free || null === $free ) {
				continue;
			}
			$need += self::margin( $total );
			if ( (float) $free < $need ) {
				return sprintf(
					/* translators: 1: space needed, e.g. "120 MB", 2: free space, e.g. "40 MB", 3: folder */
					__( 'Not enough free disk space: %1$s needed, %2$s free in %3$s.', 'elementor-podcast-manager' ),
					size_format( $need, 1 ),
					size_format( max( 0, (float) $free ), 1 ),
					$dir
				);
			}
		}

		return '';
	}

	/**
	 * Seconds to wait from a Retry-After header (seconds or an HTTP date).
	 *
	 * @param string $value Header value.
	 * @return int
	 */
	private static function retry_after( string $value ): int {
		if ( '' === $value ) {
			$seconds = MINUTE_IN_SECONDS;
		} elseif ( ctype_digit( $value ) ) {
			$seconds = (int) $value;
		} else {
			$when    = strtotime( $value );
			$seconds = false === $when ? MINUTE_IN_SECONDS : $when - time();
		}

		// A date in the past still means "not right now".
		return min( self::max_wait_seconds(), max( 30, $seconds ) );
	}

	/**
	 * "The host answered HTTP 404 (Not Found)."
	 *
	 * @param int $code Status.
	 * @return string
	 */
	public static function http_message( int $code ): string {
		$text = trim( (string) get_status_header_desc( $code ) );

		return '' !== $text
			? sprintf(
				/* translators: 1: HTTP status code, 2: status text, e.g. "Not Found" */
				__( 'The host answered HTTP %1$d (%2$s).', 'elementor-podcast-manager' ),
				$code,
				$text
			)
			: sprintf(
				/* translators: %d: HTTP status code */
				__( 'The host answered HTTP %d.', 'elementor-podcast-manager' ),
				$code
			);
	}

	/**
	 * The file is larger than the limit for its kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function too_large_message( string $kind ): string {
		return sprintf(
			/* translators: 1: size limit, e.g. "1 GB", 2: filter name */
			__( 'The file is larger than the limit of %1$s for this kind of file (filter %2$s).', 'elementor-podcast-manager' ),
			size_format( self::max_bytes( $kind ) ),
			'epm_media_max_bytes'
		);
	}

	/**
	 * The address is not a public one.
	 *
	 * @return string
	 */
	private static function unsafe_message(): string {
		return __( 'The address (or an address it redirects to) is not allowed: it is not a public web address.', 'elementor-podcast-manager' );
	}

	/**
	 * The host cannot continue a download.
	 *
	 * @return string
	 */
	private static function no_range_message(): string {
		return __( 'The host cannot continue an interrupted download (no HTTP Range support), and the file did not arrive within one request.', 'elementor-podcast-manager' );
	}

	/**
	 * The host stopped sending.
	 *
	 * @return string
	 */
	private static function stalled_message(): string {
		return sprintf(
			/* translators: %s: speed, e.g. "1 KB" */
			__( 'The host stopped sending the file (less than %s per second).', 'elementor-podcast-manager' ),
			size_format( self::low_speed()['bytes'] )
		);
	}
}
