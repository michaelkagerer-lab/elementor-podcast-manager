<?php
/**
 * Audio metadata extraction (Layer 1).
 *
 * Populates duration, file size and MIME type when an audio attachment
 * is linked to an episode. Runs on save; never blocks publishing.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AudioMetadata {

	/**
	 * Allowed audio MIME types for the uploader.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_mimes(): array {
		return apply_filters(
			'epm_allowed_audio_mimes',
			[
				'mp3' => 'audio/mpeg',
				'm4a' => 'audio/mp4',
				'wav' => 'audio/wav',
			]
		);
	}

	/**
	 * MIME types suitable for podcast distribution (RSS enclosure).
	 * WAV is accepted for internal storage but excluded from the feed.
	 *
	 * @return string[]
	 */
	public static function distribution_mimes(): array {
		return apply_filters(
			'epm_distribution_audio_mimes',
			[ 'audio/mpeg', 'audio/mp4', 'audio/x-m4a' ]
		);
	}

	/**
	 * Whether a MIME type is suitable for podcast distribution.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_distribution_format( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), self::distribution_mimes(), true );
	}

	/**
	 * Whether an attachment is a usable episode audio file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_valid_audio_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$mime = (string) get_post_mime_type( $attachment_id );

		if ( '' === $mime || strpos( $mime, 'audio/' ) !== 0 ) {
			return false;
		}

		return in_array( $mime, array_values( self::allowed_mimes() ), true );
	}

	/**
	 * Describe an audio attachment for the editor UI.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function describe( int $attachment_id ): array {
		$url  = wp_get_attachment_url( $attachment_id );
		$mime = (string) get_post_mime_type( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );

		$duration = '';
		if ( is_array( $meta ) && ! empty( $meta['length_formatted'] ) ) {
			$duration = (string) $meta['length_formatted'];
		} elseif ( is_array( $meta ) && ! empty( $meta['length'] ) ) {
			$duration = Episodes::format_duration( (int) $meta['length'] );
		}

		$size = 0;
		if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
			$size = (int) $meta['filesize'];
		} else {
			$file = get_attached_file( $attachment_id );
			if ( $file && file_exists( $file ) ) {
				$size = (int) filesize( $file );
			}
		}

		$file = get_attached_file( $attachment_id );

		return [
			'id'               => $attachment_id,
			'filename'         => $file ? basename( $file ) : '',
			'url'              => $url ? (string) $url : '',
			'mime'             => $mime,
			'duration'         => $duration,
			'size'             => $size,
			'size_formatted'   => $size > 0 ? size_format( $size ) : '',
			'is_distribution'  => self::is_distribution_format( $mime ),
		];
	}

	/**
	 * Detect duration and file size for an audio attachment.
	 * Works without assuming a local file (offloaded media safe).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{duration: string, size: int}
	 */
	public static function detect( int $attachment_id ): array {
		$attachment_meta = wp_get_attachment_metadata( $attachment_id );

		$duration = '';
		if ( is_array( $attachment_meta ) && ! empty( $attachment_meta['length_formatted'] ) ) {
			$duration = (string) $attachment_meta['length_formatted'];
		} elseif ( is_array( $attachment_meta ) && ! empty( $attachment_meta['length'] ) ) {
			$duration = Episodes::format_duration( (int) $attachment_meta['length'] );
		}

		$size = 0;
		if ( is_array( $attachment_meta ) && ! empty( $attachment_meta['filesize'] ) ) {
			$size = (int) $attachment_meta['filesize'];
		} else {
			// Local-file fallback only; offloaded media simply reports 0.
			$file = get_attached_file( $attachment_id );
			if ( $file && file_exists( $file ) ) {
				$size = (int) filesize( $file );
			}
		}

		return [
			'duration' => $duration,
			'size'     => $size,
		];
	}

	/**
	 * Validate a manual duration string.
	 * Accepts seconds ("2892"), "MM:SS" ("48:12") and "H:MM:SS" ("1:02:03").
	 *
	 * @param string $duration Duration string.
	 * @return bool
	 */
	public static function is_valid_duration( string $duration ): bool {
		$duration = trim( $duration );

		if ( '' === $duration ) {
			return true;
		}

		if ( preg_match( '/^\d+$/', $duration ) ) {
			return true;
		}

		return (bool) preg_match( '/^(?:\d+:)?[0-5]?\d:[0-5]\d$/', $duration );
	}

	/**
	 * Backfill missing cached audio metadata for an episode.
	 * Never overwrites existing values and never blocks publishing.
	 *
	 * @param int $episode_id Episode post ID.
	 * @return void
	 */
	public static function refresh( int $episode_id ): void {
		$audio_id = (int) get_post_meta( $episode_id, Episodes::META_PREFIX . 'audio_id', true );

		if ( $audio_id <= 0 || ! self::is_valid_audio_attachment( $audio_id ) ) {
			return;
		}

		$detected = self::detect( $audio_id );

		$stored_duration = (string) get_post_meta( $episode_id, Episodes::META_PREFIX . 'duration', true );
		if ( '' === $stored_duration && '' !== $detected['duration'] ) {
			update_post_meta( $episode_id, Episodes::META_PREFIX . 'duration', $detected['duration'] );
		}

		$stored_size = (int) get_post_meta( $episode_id, Episodes::META_PREFIX . 'audio_size', true );
		if ( $stored_size <= 0 && $detected['size'] > 0 ) {
			update_post_meta( $episode_id, Episodes::META_PREFIX . 'audio_size', $detected['size'] );
		}
	}

	/**
	 * Get the best enclosure data for the feed.
	 *
	 * Never invents a file size: when the size cannot be determined it is
	 * reported as 0 with size_known=false so callers can warn instead of
	 * silently emitting a wrong length.
	 *
	 * @param array<string, mixed> $episode Normalized episode data.
	 * @return array{url: string, length: int, type: string, size_known: bool, distributable: bool}
	 */
	public static function enclosure( array $episode ): array {
		$url  = (string) ( $episode['audio_url'] ?? '' );
		$type = (string) ( $episode['audio_mime'] ?? '' );

		if ( '' === $type ) {
			$type = 'audio/mpeg';
		}

		$length     = (int) ( $episode['audio_size'] ?? 0 );
		$size_known = $length > 0;

		// Last resort: resolve size from the attachment file (local files only).
		if ( ! $size_known && ! empty( $episode['audio_id'] ) ) {
			$file = get_attached_file( (int) $episode['audio_id'] );
			if ( $file && file_exists( $file ) ) {
				$length     = (int) filesize( $file );
				$size_known = $length > 0;
			}
		}

		return [
			'url'           => $url,
			'length'        => $length,
			'type'          => $type,
			'size_known'    => $size_known,
			'distributable' => '' !== $url && self::is_distribution_format( $type ),
		];
	}

	/**
	 * Find duplicate enclosure URLs across published episodes.
	 *
	 * @return array<string, array<int>> URL => episode IDs (only duplicates).
	 */
	public static function duplicate_enclosures(): array {
		$posts = get_posts(
			[
				'post_type'      => EpisodePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$by_url = [];
		foreach ( $posts as $post_id ) {
			$audio_id = (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'audio_id', true );
			if ( $audio_id <= 0 ) {
				continue;
			}
			$url = wp_get_attachment_url( $audio_id );
			if ( ! $url ) {
				continue;
			}
			$by_url[ $url ][] = $post_id;
		}

		return array_filter(
			$by_url,
			function ( $ids ) {
				return count( $ids ) > 1;
			}
		);
	}
}
