<?php
/**
 * Transcript files (WebVTT, SRT, Podcasting 2.0 JSON).
 *
 * An episode can carry a transcript file next to its readable transcript
 * text: uploaded to the Media Library (`_epm_transcript_file_id`) or hosted
 * elsewhere (`_epm_transcript_url`, e.g. kept from an imported feed). The
 * feed lists each one as a podcast:transcript, which Apple Podcasts and
 * Podcasting 2.0 apps use for captions.
 *
 * @package ElementorPodcastManager
 */

namespace EPM;

defined( 'ABSPATH' ) || exit;

/**
 * Transcript file helpers.
 */
final class Transcripts {

	/**
	 * Extension => MIME type of supported transcript documents.
	 */
	public const TYPES = [
		'vtt'  => 'text/vtt',
		'srt'  => 'application/x-subrip',
		'json' => 'application/json',
		'html' => 'text/html',
		'htm'  => 'text/html',
		'txt'  => 'text/plain',
	];

	/**
	 * Largest transcript file read from disk, in bytes.
	 */
	private const MAX_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Hook the SRT upload type check.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_check_filetype_and_ext', [ self::class, 'check_filetype' ], 10, 5 );
	}

	/**
	 * Store SRT files with their own type on every server.
	 *
	 * WordPress lists .srt as text/plain. Depending on the server's libmagic
	 * the content is detected as text/plain (accepted as text/plain) or as
	 * application/x-subrip (rejected). Both become application/x-subrip, so
	 * the transcript picker finds the file. The site's allowed file types
	 * still decide whether .srt is accepted at all, and a file detected as
	 * anything but text is never taken for an SRT.
	 *
	 * @param array<string, mixed>       $data      Result so far.
	 * @param string                     $file      Temporary file path.
	 * @param string                     $filename  Original file name.
	 * @param array<string, string>|null $mimes     Allowed types (null = site default).
	 * @param string|false               $real_mime Detected type.
	 * @return array<string, mixed>
	 */
	public static function check_filetype( $data, $file, $filename, $mimes, $real_mime = false ): array {
		$data = is_array( $data ) ? $data : [];

		if ( 'srt' !== strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) ) ) {
			return $data;
		}

		if ( empty( $data['ext'] ) ) {
			if ( ! in_array( $real_mime, [ 'text/plain', 'application/x-subrip', 'text/x-subrip', false, '' ], true ) ) {
				return $data;
			}
			$allowed = wp_check_filetype( (string) $filename, is_array( $mimes ) ? $mimes : null );
			if ( 'srt' !== $allowed['ext'] ) {
				return $data;
			}
		}

		$data['ext']  = 'srt';
		$data['type'] = self::TYPES['srt'];

		return $data;
	}

	/**
	 * MIME type from a file name or URL.
	 *
	 * @param string $name File name, path or URL.
	 * @return string MIME type or ''.
	 */
	public static function mime( string $name ): string {
		$path = (string) wp_parse_url( $name, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( '' !== $path ? $path : $name, PATHINFO_EXTENSION ) );

		return self::TYPES[ $ext ] ?? '';
	}

	/**
	 * Canonical MIME type for a declared transcript type.
	 *
	 * @param string $type Declared type (may be empty or an alias).
	 * @param string $url  Document URL, used when the type is missing.
	 * @return string
	 */
	public static function normalize_type( string $type, string $url = '' ): string {
		$type    = strtolower( trim( explode( ';', $type )[0] ) );
		$aliases = [
			'application/srt' => 'application/x-subrip',
			'text/srt'        => 'application/x-subrip',
			'text/x-srt'      => 'application/x-subrip',
			'text/x-subrip'   => 'application/x-subrip',
			'text/webvtt'     => 'text/vtt',
			'text/json'       => 'application/json',
		];
		$type = $aliases[ $type ] ?? $type;

		if ( in_array( $type, self::TYPES, true ) ) {
			return $type;
		}

		return '' !== $url ? self::mime( $url ) : '';
	}

	/**
	 * Transcript files of an episode, for the feed.
	 *
	 * The uploaded file wins over a hosted URL of the same type.
	 *
	 * @param int $post_id Episode ID.
	 * @return array<int, array{url: string, type: string}>
	 */
	public static function files( int $post_id ): array {
		$out = [];

		$file_id = (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_file_id', true );
		if ( $file_id > 0 && 'attachment' === get_post_type( $file_id ) ) {
			$url  = (string) wp_get_attachment_url( $file_id );
			$type = self::mime( (string) get_attached_file( $file_id ) );
			if ( '' === $type ) {
				$type = self::mime( $url );
			}
			if ( '' !== $url && '' !== $type ) {
				$out[] = [
					'url'  => $url,
					'type' => $type,
				];
			}
		}

		$url = esc_url_raw( (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', true ) );
		if ( '' !== $url ) {
			$type = self::normalize_type( (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_type', true ), $url );
			if ( '' !== $type && ! in_array( $type, wp_list_pluck( $out, 'type' ), true ) ) {
				$out[] = [
					'url'  => $url,
					'type' => $type,
				];
			}
		}

		/**
		 * Filter an episode's transcript files.
		 *
		 * @param array<int, array{url: string, type: string}> $out     Files.
		 * @param int                                           $post_id Episode ID.
		 */
		return (array) apply_filters( 'epm_transcript_files', $out, $post_id );
	}

	/**
	 * Readable HTML from an uploaded transcript file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string HTML, or '' when the file is missing, too large or not a transcript.
	 */
	public static function html_from_attachment( int $attachment_id ): string {
		$path = (string) get_attached_file( $attachment_id );
		$type = self::mime( $path );

		if ( '' === $path || '' === $type || ! is_readable( $path ) || (int) filesize( $path ) > self::MAX_BYTES ) {
			return '';
		}

		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.

		return '' === trim( $body ) ? '' : Importer::transcript_html( $body, $type );
	}
}
