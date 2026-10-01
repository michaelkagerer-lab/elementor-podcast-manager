<?php
/**
 * Copies one media file of an episode into the Media Library (Layer 1).
 *
 * - The file is downloaded with MediaDownload (bounded per request,
 *   continued by the next request when it takes longer).
 * - What arrived must be what was asked for: audio that WordPress can read
 *   as audio (not a web page, JSON or unknown bytes), an image, or a
 *   WebVTT/SRT file. Otherwise the copy fails with a reason that says what
 *   the host returned, and the episode keeps its address at the host.
 * - Audio never goes through media_handle_sideload(): WordPress core runs
 *   getimagesize() on every sideloaded file, which reads an audio file
 *   into memory (a fatal error for long episodes). Audio is moved with
 *   wp_handle_sideload() and gets audio metadata only.
 * - A copy that already exists is reused: an attachment made from the same
 *   source URL, or an identical file a dead request left in the uploads
 *   folder (no "-1" duplicates).
 * - The files of the copy in progress are known (abandon()), so a request
 *   that dies can remove what it left behind.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaCopy {

	/**
	 * Files of the copy this request is working on: kind, url, file (the
	 * download), target (the file in uploads), attachment.
	 *
	 * @var array<string, mixed>
	 */
	private static array $current = [];

	/**
	 * Copy (or continue copying) a file.
	 *
	 * Result:
	 * - copied:  `attachment` holds the file (`reused` when it existed);
	 * - pending: not finished in this request; pass `download` back next time;
	 * - wait:    the host asked to wait until `until`; pass `download` back then;
	 * - failed:  not copyable in this run (`reason`, `message`).
	 *
	 * @param string               $url     Source URL.
	 * @param string               $kind    audio, image or transcript.
	 * @param int                  $post_id Episode the attachment belongs to (0: none).
	 * @param string               $title   Attachment title.
	 * @param array<string, mixed> $context deadline (microtime, default 30 s from now),
	 *                                      download (state of an earlier request),
	 *                                      hint (size the feed announces).
	 * @return array<string, mixed>
	 */
	public static function copy( string $url, string $kind, int $post_id, string $title, array $context = [] ): array {
		$url      = esc_url_raw( $url );
		$deadline = (float) ( $context['deadline'] ?? microtime( true ) + 30 );
		$out      = [
			'result'     => 'failed',
			'attachment' => 0,
			'reused'     => false,
			'download'   => [],
			'reason'     => '',
			'message'    => '',
			'until'      => 0,
		];

		if ( '' === $url ) {
			$out['reason']  = 'url';
			$out['message'] = __( 'The address is not a valid web address.', 'elementor-podcast-manager' );
			return $out;
		}

		$existing = self::existing( $url, $kind );
		if ( $existing > 0 ) {
			$out['result']     = 'copied';
			$out['attachment'] = $existing;
			$out['reused']     = true;
			return $out;
		}

		$d = (array) ( $context['download'] ?? [] );
		if ( ( $d['url'] ?? '' ) !== $url ) {
			$d = MediaDownload::fresh( $url, $kind, (int) ( $context['hint'] ?? 0 ) );
		}

		self::$current = [
			'kind' => $kind,
			'url'  => $url,
			'file' => (string) ( $d['file'] ?? '' ),
		];

		for ( $guard = 0; $guard < 20; $guard++ ) {
			$d                     = MediaDownload::run( $d, $deadline );
			self::$current['file'] = (string) $d['file'];

			if ( 'complete' === $d['result'] ) {
				break;
			}
			if ( 'retry' === $d['result'] && $deadline - microtime( true ) > 2.0 ) {
				continue;
			}
			if ( 'failed' === $d['result'] ) {
				self::$current = [];
				$out['reason']  = (string) $d['reason'];
				$out['message'] = (string) $d['message'];
				return $out;
			}

			// partial, wait, or a retry for the next request.
			self::$current     = [];
			$out['result']     = 'wait' === $d['result'] ? 'wait' : 'pending';
			$out['download']   = $d;
			$out['until']      = (int) $d['until'];
			$out['reason']     = (string) $d['reason'];
			$out['message']    = (string) $d['message'];
			return $out;
		}

		$checked = self::validate( $d, $kind );
		if ( is_wp_error( $checked ) ) {
			MediaDownload::discard( $d );
			self::$current  = [];
			$out['reason']  = (string) $checked->get_error_code();
			$out['message'] = $checked->get_error_message();
			return $out;
		}

		$attachment = self::store( (string) $d['file'], $checked, $kind, $post_id, $title, $url );
		MediaDownload::discard( $d );
		self::$current = [];

		if ( is_wp_error( $attachment ) ) {
			$out['reason']  = 'store';
			$out['message'] = sprintf(
				/* translators: %s: error message */
				__( 'The file could not be added to the Media Library: %s', 'elementor-podcast-manager' ),
				rtrim( $attachment->get_error_message(), '.' ) . '.'
			);
			return $out;
		}

		$out['result']     = 'copied';
		$out['attachment'] = $attachment;

		return $out;
	}

	/**
	 * The copy in progress (empty when none).
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		return self::$current;
	}

	/**
	 * A request is dying in the middle of a copy: remove what the copy left
	 * (the download, a file moved into uploads that is no attachment yet,
	 * an attachment no episode uses yet).
	 *
	 * @return array<string, mixed> The copy that was abandoned (kind, url).
	 */
	public static function abandon(): array {
		$current       = self::$current;
		self::$current = [];
		if ( empty( $current ) ) {
			return [];
		}

		$attachment = (int) ( $current['attachment'] ?? 0 );
		if ( $attachment > 0 ) {
			wp_delete_attachment( $attachment, true );
		}
		$target = (string) ( $current['target'] ?? '' );
		if ( '' !== $target && is_file( $target ) && 0 === self::attachment_for_file( $target ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		MediaDownload::discard( [ 'file' => (string) ( $current['file'] ?? '' ) ] );

		return $current;
	}

	/**
	 * An attachment copied from this URL whose file is still there.
	 *
	 * @param string $url  Source URL.
	 * @param string $kind audio, image or transcript.
	 * @return int Attachment ID or 0.
	 */
	public static function existing( string $url, string $kind ): int {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => '_epm_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		foreach ( $ids as $id ) {
			$file = (string) get_attached_file( (int) $id );
			$mime = (string) get_post_mime_type( (int) $id );
			$fits = 'audio' === $kind ? 0 === strpos( $mime, 'audio/' ) : ( 'image' === $kind ? 0 === strpos( $mime, 'image/' ) : in_array( Transcripts::mime( $file ), [ 'text/vtt', 'application/x-subrip' ], true ) );
			if ( $fits && '' !== $file && is_file( $file ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Check that a downloaded file is what it should be.
	 *
	 * @param array<string, mixed> $d    Completed download.
	 * @param string               $kind audio, image or transcript.
	 * @return array{ext: string, mime: string, meta: array<string, mixed>}|\WP_Error
	 */
	private static function validate( array $d, string $kind ) {
		$file = (string) $d['file'];
		$head = (string) file_get_contents( $file, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		$what = self::document( $head );
		$noun = [
			'audio'      => __( 'the audio file', 'elementor-podcast-manager' ),
			'image'      => __( 'the image', 'elementor-podcast-manager' ),
			'transcript' => __( 'the transcript file', 'elementor-podcast-manager' ),
		][ $kind ] ?? __( 'the file', 'elementor-podcast-manager' );

		if ( '' === trim( $head ) ) {
			return new \WP_Error( 'empty', __( 'The host returned an empty file.', 'elementor-podcast-manager' ) );
		}

		if ( 'image' === $kind ) {
			$mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $file ) : '';
			$ext  = [
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
			][ $mime ] ?? '';
			if ( '' === $ext ) {
				return self::wrong( $what, $noun, 'not_image', __( 'The host returned a file that is not an image (JPEG, PNG, GIF or WebP).', 'elementor-podcast-manager' ) );
			}
			return [
				'ext'  => $ext,
				'mime' => $mime,
				'meta' => [],
			];
		}

		if ( 'transcript' === $kind ) {
			$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $head );
			if ( 0 === strpos( ltrim( $text ), 'WEBVTT' ) ) {
				$ext = 'vtt';
			} elseif ( preg_match( '/^\s*\d+\s*\R\s*\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}\s*-->/', $text ) ) {
				$ext = 'srt';
			} else {
				return self::wrong( $what, $noun, 'not_transcript', __( 'The host returned a file that is neither WebVTT nor SRT.', 'elementor-podcast-manager' ) );
			}
			return [
				'ext'  => $ext,
				'mime' => Transcripts::TYPES[ $ext ],
				'meta' => [],
			];
		}

		// Audio.
		if ( '' !== $what ) {
			return self::wrong( $what, $noun, 'not_audio', '' );
		}

		if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$format = self::audio_format( $head );
		$meta   = wp_read_audio_metadata( $file );
		$meta   = is_array( $meta ) ? $meta : [];
		$found  = strtolower( (string) ( $meta['fileformat'] ?? '' ) );
		if ( '' === $format ) {
			$format = [
				'mp3'       => 'mp3',
				'mp4'       => 'm4a',
				'quicktime' => 'm4a',
				'riff'      => 'wav',
				'wav'       => 'wav',
			][ $found ] ?? '';
		}
		$readable = '' !== $found && ( (float) ( $meta['length'] ?? 0 ) > 0 || (float) ( $meta['bitrate'] ?? 0 ) > 0 );

		if ( '' === $format || ! $readable ) {
			$type = function_exists( 'mime_content_type' ) ? (string) @mime_content_type( $file ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error(
				'not_audio',
				'' !== $type && 'application/octet-stream' !== $type
					? sprintf(
						/* translators: %s: detected file type, e.g. "application/zip" */
						__( 'The host returned a file that is not audio (%s).', 'elementor-podcast-manager' ),
						$type
					)
					: __( 'The host returned data that is not an audio file WordPress can read.', 'elementor-podcast-manager' )
			);
		}

		$allowed = array_keys( AudioMetadata::allowed_mimes() );
		if ( ! in_array( $format, $allowed, true ) ) {
			return new \WP_Error(
				'format',
				sprintf(
					/* translators: 1: audio format, e.g. "AAC", 2: list of formats, e.g. "MP3, M4A, WAV" */
					__( 'Audio in the %1$s format is not copied (only %2$s are); it keeps playing from the host.', 'elementor-podcast-manager' ),
					strtoupper( $format ),
					strtoupper( implode( ', ', $allowed ) )
				)
			);
		}

		// Without a length from the host, a file far smaller than the feed
		// announces is probably cut off.
		$hint = (int) ( $d['hint'] ?? 0 );
		if ( empty( $d['length'] ) && $hint >= MB_IN_BYTES && (int) $d['bytes'] < $hint / 2 ) {
			return new \WP_Error(
				'short',
				sprintf(
					/* translators: 1: bytes received, e.g. "2 MB", 2: size the feed announces, e.g. "40 MB" */
					__( 'The download ended after %1$s, but the feed announces %2$s, so the file is probably incomplete.', 'elementor-podcast-manager' ),
					size_format( (int) $d['bytes'], 1 ),
					size_format( $hint, 1 )
				)
			);
		}

		return [
			'ext'  => $format,
			'mime' => (string) ( wp_check_filetype( 'x.' . $format )['type'] ?: 'audio/mpeg' ),
			'meta' => $meta,
		];
	}

	/**
	 * What a non-media answer is: html, json, xml, text or '' (none of
	 * these).
	 *
	 * @param string $head First bytes.
	 * @return string
	 */
	private static function document( string $head ): string {
		$text = ltrim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $head ) );
		if ( preg_match( '/^(<!doctype\s+html|<html|<head|<body|<\?xml[^>]*>\s*<(!doctype\s+)?html)/i', $text ) || preg_match( '/<html[\s>]/i', substr( $text, 0, 512 ) ) ) {
			return 'html';
		}
		if ( '' !== $text && in_array( $text[0], [ '{', '[' ], true ) && null !== json_decode( $head, true ) ) {
			return 'json';
		}
		if ( 0 === strpos( $text, '<?xml' ) || ( '' !== $text && '<' === $text[0] ) ) {
			return 'xml';
		}
		if ( '' !== $text && ! preg_match( '/[\x00-\x08\x0E-\x1F]/', $head ) && preg_match( '//u', $head ) && ! preg_match( '/^(ID3|WEBVTT|RIFF|OggS|fLaC)/', $text ) ) {
			return 'text';
		}

		return '';
	}

	/**
	 * Audio format from the first bytes: mp3, m4a, wav, aac, ogg, flac or ''.
	 *
	 * @param string $head First bytes.
	 * @return string
	 */
	private static function audio_format( string $head ): string {
		if ( 0 === strpos( $head, 'ID3' ) ) {
			return 'mp3';
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) ) {
			return 'm4a';
		}
		if ( 0 === strpos( $head, 'RIFF' ) && 'WAVE' === substr( $head, 8, 4 ) ) {
			return 'wav';
		}
		if ( 0 === strpos( $head, 'OggS' ) ) {
			return 'ogg';
		}
		if ( 0 === strpos( $head, 'fLaC' ) ) {
			return 'flac';
		}
		if ( strlen( $head ) >= 4 && "\xFF" === $head[0] && ( ord( $head[1] ) & 0xE0 ) === 0xE0 ) {
			// Layer bits 00: AAC in ADTS frames; anything else: MPEG audio.
			return 0 === ( ord( $head[1] ) & 0x06 ) ? 'aac' : 'mp3';
		}

		return '';
	}

	/**
	 * The host returned something else than the file.
	 *
	 * @param string $what     html, json, xml, text or ''.
	 * @param string $noun     "the audio file", "the image" …
	 * @param string $code     Error code.
	 * @param string $fallback Message when $what is ''.
	 * @return \WP_Error
	 */
	private static function wrong( string $what, string $noun, string $code, string $fallback ): \WP_Error {
		switch ( $what ) {
			case 'html':
				/* translators: %s: "the audio file", "the image" or "the transcript file" */
				$message = sprintf( __( 'The host returned a web page instead of %s (often a login, error or consent page).', 'elementor-podcast-manager' ), $noun );
				break;
			case 'json':
				/* translators: %s: "the audio file", "the image" or "the transcript file" */
				$message = sprintf( __( 'The host returned data (JSON) instead of %s, usually an error message.', 'elementor-podcast-manager' ), $noun );
				break;
			case 'xml':
				/* translators: %s: "the audio file", "the image" or "the transcript file" */
				$message = sprintf( __( 'The host returned an XML document instead of %s, usually an error message of its storage.', 'elementor-podcast-manager' ), $noun );
				break;
			case 'text':
				/* translators: %s: "the audio file", "the image" or "the transcript file" */
				$message = sprintf( __( 'The host returned a text message instead of %s.', 'elementor-podcast-manager' ), $noun );
				break;
			default:
				$message = '' !== $fallback ? $fallback : __( 'The host returned data that is not an audio file WordPress can read.', 'elementor-podcast-manager' );
		}

		return new \WP_Error( $code, $message );
	}

	/**
	 * Add a checked file to the Media Library.
	 *
	 * @param string                                                   $tmp     Downloaded file.
	 * @param array{ext: string, mime: string, meta: array<string, mixed>} $checked From validate().
	 * @param string                                                   $kind    audio, image or transcript.
	 * @param int                                                      $post_id Parent post.
	 * @param string                                                   $title   Title.
	 * @param string                                                   $url     Source URL.
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function store( string $tmp, array $checked, string $kind, int $post_id, string $title, string $url ) {
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$name = self::file_name( $url, $title, $kind, $checked['ext'] );
		$size = (int) filesize( $tmp );

		// Attachments of a post go to the post's month folder, like
		// media_handle_sideload() does.
		$time = null;
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post && 'page' !== $post->post_type && (int) substr( (string) $post->post_date, 0, 4 ) > 0 ) {
			$time = (string) $post->post_date;
		}

		$uploads = wp_upload_dir( $time );
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'upload_dir', (string) $uploads['error'] );
		}
		self::adopt_identical( (string) $uploads['path'], $name, $tmp, $size );

		$file  = [
			'name'     => $name,
			'tmp_name' => $tmp,
			'size'     => $size,
			'error'    => 0,
			'type'     => $checked['mime'],
		];
		$mover = static function ( $move, $upload, $new_file ) use ( $tmp ) {
			if ( ! is_array( $upload ) || ( $upload['tmp_name'] ?? '' ) !== $tmp ) {
				return $move;
			}
			self::$current['target'] = (string) $new_file;
			if ( ! self::move( $tmp, (string) $new_file ) ) {
				// WordPress skips its own error handling for a move done by
				// this filter: stop the upload here.
				throw new \RuntimeException( 'epm_move_failed' );
			}
			return true;
		};
		add_filter( 'pre_move_uploaded_file', $mover, 10, 3 );
		try {
			$moved = wp_handle_sideload( $file, [ 'test_form' => false ], $time );
		} catch ( \RuntimeException $e ) {
			if ( 'epm_move_failed' !== $e->getMessage() ) {
				throw $e;
			}
			return new \WP_Error(
				'upload',
				sprintf(
					/* translators: %s: folder */
					__( 'The file could not be moved to %s (is the disk full?).', 'elementor-podcast-manager' ),
					(string) $uploads['path']
				)
			);
		} finally {
			remove_filter( 'pre_move_uploaded_file', $mover, 10 );
		}

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			return new \WP_Error( 'upload', is_array( $moved ) ? (string) $moved['error'] : __( 'Unknown error.', 'elementor-podcast-manager' ) );
		}

		$attachment = wp_insert_attachment(
			[
				'post_mime_type' => (string) $moved['type'],
				'guid'           => (string) $moved['url'],
				'post_parent'    => $post_id,
				'post_title'     => '' !== $title ? $title : pathinfo( $name, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			(string) $moved['file'],
			$post_id,
			true
		);
		if ( is_wp_error( $attachment ) ) {
			@unlink( (string) $moved['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $attachment;
		}
		$attachment                  = (int) $attachment;
		self::$current['attachment'] = $attachment;
		update_post_meta( $attachment, '_epm_source_url', $url );

		if ( 'audio' === $kind ) {
			// Audio metadata only: no image probe of the audio file.
			$meta             = $checked['meta'];
			$meta['filesize'] = $size;
		} elseif ( 'image' === $kind ) {
			$meta = wp_generate_attachment_metadata( $attachment, (string) $moved['file'] );
		} else {
			$meta = [ 'filesize' => $size ];
		}
		wp_update_attachment_metadata( $attachment, is_array( $meta ) ? $meta : [] );

		return $attachment;
	}

	/**
	 * Move the download into the uploads folder: renamed when it can be
	 * (same disk: instant, no second copy), otherwise copied; a file that
	 * did not arrive completely is removed again.
	 *
	 * @param string $from Download.
	 * @param string $to   Target in uploads.
	 * @return bool
	 */
	private static function move( string $from, string $to ): bool {
		$size = (int) filesize( $from );
		$done = @rename( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		clearstatcache( true, $to );
		if ( ! $done || ! is_file( $to ) || (int) filesize( $to ) !== $size ) {
			if ( is_file( $to ) ) {
				@unlink( $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return false;
		}

		return true;
	}

	/**
	 * A file of the same name and content that no attachment uses (a
	 * request died after moving it there) is replaced, so the copy keeps
	 * its name instead of becoming "name-1".
	 *
	 * @param string $dir  Uploads folder of the copy.
	 * @param string $name File name.
	 * @param string $tmp  Downloaded file.
	 * @param int    $size Its size.
	 * @return void
	 */
	private static function adopt_identical( string $dir, string $name, string $tmp, int $size ): void {
		$candidate = trailingslashit( $dir ) . sanitize_file_name( $name );
		if ( ! is_file( $candidate ) || (int) filesize( $candidate ) !== $size || self::attachment_for_file( $candidate ) > 0 ) {
			return;
		}
		if ( self::fingerprint( $candidate, $size ) === self::fingerprint( $tmp, $size ) ) {
			@unlink( $candidate ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Checksum of a file's first and last megabyte.
	 *
	 * @param string $file Path.
	 * @param int    $size Size.
	 * @return string
	 */
	private static function fingerprint( string $file, int $size ): string {
		$head = (string) file_get_contents( $file, false, null, 0, MB_IN_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$tail = $size > 2 * MB_IN_BYTES ? (string) file_get_contents( $file, false, null, $size - MB_IN_BYTES, MB_IN_BYTES ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return md5( $head ) . md5( $tail ) . $size;
	}

	/**
	 * The attachment whose file this is (0 when none).
	 *
	 * @param string $file Path in the uploads folder.
	 * @return int
	 */
	private static function attachment_for_file( string $file ): int {
		global $wpdb;

		if ( ! function_exists( '_wp_relative_upload_path' ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup before a file is replaced.
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				_wp_relative_upload_path( $file )
			)
		);
	}

	/**
	 * File name for a copy: the source's name with the extension of what
	 * was actually downloaded.
	 *
	 * @param string $url   Source URL.
	 * @param string $title Episode title.
	 * @param string $kind  Kind.
	 * @param string $ext   Extension.
	 * @return string
	 */
	private static function file_name( string $url, string $title, string $kind, string $ext ): string {
		$base = pathinfo( sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ), PATHINFO_FILENAME );
		if ( '' === $base ) {
			$base = sanitize_title( $title );
		}
		if ( '' === $base ) {
			$base = 'podcast-' . $kind;
		}

		return $base . '.' . $ext;
	}
}
