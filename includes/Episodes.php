<?php
/**
 * Episode data layer: queries and normalized episode data (Layer 1).
 *
 * Returns plain arrays so the renderer, feed, shortcodes and widgets
 * share one source of truth. Multi-podcast support stays possible:
 * queries are filtered by post type and a future podcast taxonomy/term.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Episodes {

	/**
	 * Meta key prefix.
	 */
	public const META_PREFIX = '_epm_';

	/**
	 * Per-request cache of normalized episode data.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $data_cache = [];

	/**
	 * Hook init.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ EpisodePostType::class, 'register' ], 5 );
		add_action( 'init', [ $this, 'maybe_migrate_guids' ], 20 );
		add_filter( 'posts_clauses', [ $this, 'order_by_number_clauses' ], 10, 2 );
	}

	/**
	 * Order by episode number without losing episodes that have none.
	 *
	 * A plain meta_key ordering joins the meta table with an INNER JOIN, so
	 * trailers, bonus episodes and imported episodes without a number
	 * disappeared from the list. Here the number is joined optionally:
	 * numbered episodes come first in the requested order, the others
	 * after them, by date in the same direction.
	 *
	 * @param array<string, string> $clauses Query clauses.
	 * @param \WP_Query            $query   Query.
	 * @return array<string, string>
	 */
	public function order_by_number_clauses( $clauses, $query ) {
		if ( ! $query instanceof \WP_Query || ! is_array( $clauses ) ) {
			return $clauses;
		}

		$order = (string) $query->get( 'epm_order_by_number' );
		if ( '' === $order ) {
			return $clauses;
		}

		global $wpdb;
		$order = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS epm_number ON ( epm_number.post_id = {$wpdb->posts}.ID AND epm_number.meta_key = %s )",
			self::META_PREFIX . 'episode_number'
		);
		$clauses['orderby'] = "CASE WHEN epm_number.meta_value IS NULL OR epm_number.meta_value = '' THEN 1 ELSE 0 END ASC, epm_number.meta_value+0 {$order}, {$wpdb->posts}.post_date {$order}, {$wpdb->posts}.ID {$order}";

		return $clauses;
	}

	/**
	 * One-time idempotent migration: persist immutable GUIDs.
	 *
	 * Episodes that already exist keep their already-issued GUID
	 * byte-for-byte (the legacy home_url-based form), so podcast clients
	 * never see a duplicate. New episodes receive domain-independent
	 * URN GUIDs on save.
	 *
	 * @return void
	 */
	public function maybe_migrate_guids(): void {
		if ( get_option( 'epm_guids_migrated', false ) ) {
			return;
		}

		$ids = get_posts(
			[
				'post_type'      => EpisodePostType::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		foreach ( $ids as $id ) {
			if ( '' === (string) get_post_meta( $id, self::META_PREFIX . 'guid', true ) ) {
				// Preserve the already-issued identity exactly.
				update_post_meta( $id, self::META_PREFIX . 'guid', home_url( '/?epm_episode_guid=' . (int) $id ) );
			}
		}

		update_option( 'epm_guids_migrated', true );
	}

	/**
	 * Get the immutable GUID for an episode. Generated once, never changes —
	 * title, slug, domain and HTTP/HTTPS changes do not regenerate it.
	 *
	 * @param int $post_id Episode post ID.
	 * @return string
	 */
	public static function get_guid( int $post_id ): string {
		$guid = (string) get_post_meta( $post_id, self::META_PREFIX . 'guid', true );

		if ( '' !== $guid ) {
			return $guid;
		}

		// Domain-independent URN for episodes created after the migration.
		$guid = 'urn:uuid:' . wp_generate_uuid4();
		update_post_meta( $post_id, self::META_PREFIX . 'guid', $guid );

		return $guid;
	}

	/**
	 * Prime attachment post caches for a set of episodes.
	 *
	 * Episode queries prime episode postmeta, but the referenced audio and
	 * artwork attachments would otherwise each cost a query. Call before
	 * rendering lists or feeds with many episodes.
	 *
	 * @param array<int, \WP_Post> $posts Episode posts.
	 * @return void
	 */
	public static function prime_attachments( array $posts ): void {
		$ids = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			foreach ( [ 'audio_id', 'artwork_id' ] as $key ) {
				$id = (int) get_post_meta( $post->ID, self::META_PREFIX . $key, true );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		$ids = array_unique( $ids );
		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids );
		}
	}

	/**
	 * Prime attachment post caches for episode IDs.
	 *
	 * @param array<int> $ids Episode post IDs.
	 * @return void
	 */
	public static function prime_attachments_by_ids( array $ids ): void {
		$attachment_ids = [];
		foreach ( $ids as $id ) {
			foreach ( [ 'audio_id', 'artwork_id' ] as $key ) {
				$attachment_id = (int) get_post_meta( $id, self::META_PREFIX . $key, true );
				if ( $attachment_id > 0 ) {
					$attachment_ids[] = $attachment_id;
				}
			}
		}

		$attachment_ids = array_unique( $attachment_ids );
		if ( ! empty( $attachment_ids ) ) {
			_prime_post_caches( $attachment_ids );
		}
	}

	/**
	 * Meta query clause: episodes with audio, either a Media Library file or
	 * an audio URL. Both sub-clauses use operators WordPress can serve from
	 * one postmeta join.
	 *
	 * @return array<string, mixed>
	 */
	public static function audio_meta_query(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => self::META_PREFIX . 'audio_id',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			],
			[
				'key'     => self::META_PREFIX . 'audio_url',
				'value'   => '://',
				'compare' => 'LIKE',
			],
		];
	}

	/**
	 * Store the episode duration in seconds (_epm_duration_seconds) next to
	 * the human-readable duration, for numeric sorting and integrations.
	 * Falls back to the length WordPress read from the audio file.
	 *
	 * @param int $post_id Episode post ID.
	 * @return int Seconds stored.
	 */
	public static function sync_duration_seconds( int $post_id ): int {
		$duration = (string) get_post_meta( $post_id, self::META_PREFIX . 'duration', true );
		$seconds  = self::duration_to_seconds( $duration );

		if ( 0 === $seconds ) {
			$audio_id = (int) get_post_meta( $post_id, self::META_PREFIX . 'audio_id', true );
			$meta     = $audio_id > 0 ? wp_get_attachment_metadata( $audio_id ) : [];
			$seconds  = is_array( $meta ) && ! empty( $meta['length'] ) ? (int) $meta['length'] : 0;
		}

		update_post_meta( $post_id, self::META_PREFIX . 'duration_seconds', $seconds );

		return $seconds;
	}

	/**
	 * Clear the per-request data cache (e.g. after metadata changes).
	 *
	 * @param int $post_id Episode post ID.
	 * @return void
	 */
	public static function clear_data_cache( int $post_id ): void {
		unset( self::$data_cache[ $post_id ] );
	}

	/**
	 * Get published episodes as a WP_Query (for pagination).
	 *
	 * @param array $args Optional overrides.
	 * @return \WP_Query
	 */
	public function query( array $args = [] ): \WP_Query {
		$defaults = [
			'post_type'      => EpisodePostType::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'has_password'   => false,
		];

		$query_args = wp_parse_args( $args, $defaults );

		if ( 'episode_number' === ( $query_args['orderby'] ?? '' ) ) {
			// See order_by_number_clauses(): episodes without a number stay.
			$query_args['epm_order_by_number'] = 'ASC' === strtoupper( (string) ( $query_args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
			$query_args['orderby']             = 'date';
		}

		if ( ! empty( $args['season'] ) ) {
			$meta_query   = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) ? $query_args['meta_query'] : [];
			$meta_query[] = [
				'key'     => self::META_PREFIX . 'season_number',
				'value'   => (int) $args['season'],
				'compare' => '=',
				'type'    => 'NUMERIC',
			];
			$query_args['meta_query'] = $meta_query;
		}
		unset( $query_args['season'] );

		$query_args = apply_filters( 'epm_episode_query_args', $query_args, $args );

		return new \WP_Query( $query_args );
	}

	/**
	 * Whether an episode post is publicly visible (no authorization).
	 * Public means: published, not password-protected, not scheduled for
	 * the future, not private/draft/trashed.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	public static function is_publicly_visible( $post ): bool {
		$post = get_post( $post );

		if ( ! $post || EpisodePostType::CPT !== $post->post_type ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( '' !== $post->post_password ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the current user may preview an episode regardless of status.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	public static function can_preview( $post ): bool {
		$post = get_post( $post );

		if ( ! $post || EpisodePostType::CPT !== $post->post_type ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Get publicly-visible episode data. Returns null for anything that is
	 * not publicly visible (draft, private, future, trashed, passworded).
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return array<string, mixed>|null
	 */
	public function get_public_data( $post ): ?array {
		if ( ! self::is_publicly_visible( $post ) ) {
			return null;
		}

		return $this->get_data( $post );
	}

	/**
	 * Get episode data for authorized preview (editors). Falls back to
	 * public data for everyone else — never leaks restricted content.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return array<string, mixed>|null
	 */
	public function get_preview_data( $post ): ?array {
		$post = get_post( $post );

		if ( ! $post ) {
			return null;
		}

		if ( self::is_publicly_visible( $post ) ) {
			return $this->get_data( $post );
		}

		if ( self::can_preview( $post ) ) {
			return $this->get_data( $post );
		}

		return null;
	}

	/**
	 * Get published episodes.
	 *
	 * Only publicly-visible episodes are returned: password-protected
	 * posts are excluded via has_password=false.
	 *
	 * @param array $args Optional overrides: number, order, orderby, season, paged.
	 * @return \WP_Post[]
	 */
	public function get_episodes( array $args = [] ): array {
		$args['no_found_rows'] = true;

		return $this->query( $args )->posts;
	}

	/**
	 * Get the latest published episode.
	 *
	 * @param bool $with_audio Only consider episodes with an attached audio
	 *                         file (what listeners mean by "latest episode":
	 *                         players, the latest-episode widget and CTA).
	 * @return \WP_Post|null
	 */
	public function get_latest( bool $with_audio = false ): ?\WP_Post {
		$args = [
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $with_audio ) {
			$args['meta_query'] = [ self::audio_meta_query() ];
		}

		$episodes = $this->get_episodes( $args );

		return $episodes[0] ?? null;
	}

	/**
	 * Count published episodes.
	 *
	 * @return int
	 */
	public function count_published(): int {
		$counts = wp_count_posts( EpisodePostType::CPT );

		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * Normalize a post into an episode data array.
	 * Results are cached per request; use clear_data_cache() after writes.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return array<string, mixed>|null
	 */
	public function get_data( $post ): ?array {
		$post = get_post( $post );

		if ( ! $post || EpisodePostType::CPT !== $post->post_type ) {
			return null;
		}

		if ( isset( self::$data_cache[ $post->ID ] ) ) {
			return self::$data_cache[ $post->ID ];
		}

		$meta = function ( string $key, $default = '' ) use ( $post ) {
			$value = get_post_meta( $post->ID, self::META_PREFIX . $key, true );

			return '' === $value ? $default : $value;
		};

		$settings = epm()->settings;

		$audio_id   = (int) $meta( 'audio_id', 0 );
		$audio_url  = $audio_id > 0 ? wp_get_attachment_url( $audio_id ) : '';
		$audio_meta = $audio_id > 0 ? wp_get_attachment_metadata( $audio_id ) : [];
		$audio_mime = $audio_id > 0 ? AudioMetadata::attachment_mime( $audio_id ) : '';
		$audio_source = $audio_url ? 'media' : '';

		// Audio hosted elsewhere (a podcast host, CDN or storage bucket):
		// used when no Media Library file is attached.
		if ( ! $audio_url ) {
			$external = esc_url_raw( (string) $meta( 'audio_url', '' ) );
			if ( '' !== $external ) {
				$audio_url    = $external;
				$audio_mime   = AudioMetadata::normalize_mime( (string) $meta( 'audio_type', '' ), $external );
				$audio_meta   = [ 'filesize' => (int) $meta( 'audio_length', 0 ) ];
				$audio_source = 'external';
			}
		}

		// Artwork fallback: episode artwork -> featured image -> default
		// episode artwork -> podcast artwork.
		$episode_artwork_id = (int) $meta( 'artwork_id', 0 );
		$artwork_id         = $episode_artwork_id;
		if ( $artwork_id <= 0 ) {
			$artwork_id = (int) get_post_thumbnail_id( $post );
		}
		if ( $artwork_id <= 0 ) {
			$artwork_id = (int) $settings->get( 'default_artwork_id' );
		}
		if ( $artwork_id <= 0 ) {
			$artwork_id = (int) $settings->get( 'artwork_id' );
		}

		$explicit = $meta( 'explicit', 'inherit' );
		if ( 'inherit' === $explicit ) {
			$explicit = $settings->get( 'explicit' );
		}

		// Duration: the stored (detected or manual) value wins; otherwise
		// fall back to the length WordPress read from the audio file.
		$duration = (string) $meta( 'duration', '' );
		if ( '' === $duration && is_array( $audio_meta ) && ! empty( $audio_meta['length'] ) ) {
			$duration = self::format_duration( (int) $audio_meta['length'] );
		}

		$audio_size = ( is_array( $audio_meta ) && ! empty( $audio_meta['filesize'] ) )
			? (int) $audio_meta['filesize']
			: (int) $meta( 'audio_size', 0 );

		// Image hosted elsewhere (imported episodes keep the host's image
		// unless media was copied): used when no attachment is set.
		$own_artwork_id  = $episode_artwork_id > 0 ? $episode_artwork_id : (int) get_post_thumbnail_id( $post );
		$own_artwork_url = $own_artwork_id > 0 ? '' : esc_url_raw( (string) $meta( 'artwork_url', '' ) );

		$data = [
			'id'              => $post->ID,
			'title'           => get_the_title( $post ),
			'slug'            => $post->post_name,
			'url'             => get_permalink( $post ),
			'date'            => get_the_date( '', $post ),
			'date_rfc2822'    => get_post_time( 'r', true, $post ),
			'short_description' => $meta( 'short_description', '' ),
			'description'     => $post->post_content,
			'audio_id'        => $audio_id,
			'audio_url'       => $audio_url ? (string) $audio_url : '',
			'audio_mime'      => $audio_mime,
			'audio_size'      => $audio_size,
			'audio_source'    => $audio_source,
			'duration'        => $duration,
			'duration_seconds' => self::duration_to_seconds( $duration ),
			'artwork_id'      => $artwork_id,
			// Episode-specific image (own artwork or featured image), 0 when
			// the episode inherits the default/podcast artwork.
			'own_artwork_id'  => $own_artwork_id,
			// Remote episode image (URL) when no attachment is set.
			'own_artwork_url' => $own_artwork_url,
			// What to display: a remote episode image beats the inherited
			// default/podcast artwork (artwork_id), but not an attachment.
			'artwork_url'     => ( $own_artwork_id <= 0 && '' !== $own_artwork_url ) ? $own_artwork_url : '',
			'source'          => (string) $meta( 'source', '' ),
			'source_link'     => esc_url_raw( (string) $meta( 'source_link', '' ) ),
			'episode_number'  => $meta( 'episode_number', '' ),
			'season_number'   => $meta( 'season_number', '' ),
			'episode_type'    => $meta( 'episode_type', 'full' ),
			'explicit'        => $explicit,
			'guest_name'      => $meta( 'guest_name', '' ),
			'guest_role'      => $meta( 'guest_role', '' ),
			'guest_company'   => $meta( 'guest_company', '' ),
			'guest_image_id'  => (int) $meta( 'guest_image_id', 0 ),
			'guest_bio'       => $meta( 'guest_bio', '' ),
			'video_url'       => esc_url_raw( $meta( 'video_url', '' ) ),
			'youtube_url'     => esc_url_raw( $meta( 'youtube_url', '' ) ),
			'transcript'      => $meta( 'transcript', '' ),
			'transcript_files' => Transcripts::files( $post->ID ),
			'show_notes'      => $meta( 'show_notes', '' ),
			'chapters'        => self::normalize_chapters( $meta( 'chapters', [] ) ),
			'canonical_url'   => esc_url_raw( $meta( 'canonical_url', '' ) ),
			'platform_urls'   => self::normalize_links( $meta( 'platform_urls', [] ) ),
			'has_audio'       => '' !== (string) $audio_url,
			'guid'            => self::get_guid( $post->ID ),
		];

		$data = apply_filters( 'epm_episode_data', $data, $post );

		self::$data_cache[ $post->ID ] = $data;

		return $data;
	}

	/**
	 * Normalize stored chapters.
	 *
	 * @param mixed $chapters Raw meta.
	 * @return array<int, array{time: string, seconds: int, title: string, url: string}>
	 */
	public static function normalize_chapters( $chapters ): array {
		if ( ! is_array( $chapters ) ) {
			return [];
		}

		$out = [];
		foreach ( $chapters as $chapter ) {
			if ( ! is_array( $chapter ) ) {
				continue;
			}
			$time = sanitize_text_field( $chapter['time'] ?? '' );
			$title = sanitize_text_field( $chapter['title'] ?? '' );
			if ( '' === $time || '' === $title ) {
				continue;
			}
			$out[] = [
				'time'    => $time,
				'seconds' => self::timestamp_to_seconds( $time ),
				'title'   => $title,
				'url'     => esc_url_raw( $chapter['url'] ?? '' ),
			];
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['seconds'] <=> $b['seconds'];
			}
		);

		return array_slice( $out, 0, 200 );
	}

	/**
	 * Normalize stored link lists.
	 *
	 * @param mixed $links Raw meta.
	 * @return array<int, array{label: string, url: string, service: string}>
	 */
	public static function normalize_links( $links ): array {
		if ( ! is_array( $links ) ) {
			return [];
		}

		$out = [];
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = esc_url_raw( $link['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$out[] = [
				'label'   => sanitize_text_field( $link['label'] ?? '' ),
				'url'     => $url,
				'service' => sanitize_key( $link['service'] ?? 'custom' ),
			];
		}

		return array_slice( $out, 0, 20 );
	}

	/**
	 * Convert "HH:MM:SS" / "MM:SS" / seconds to seconds.
	 *
	 * @param string $timestamp Timestamp string.
	 * @return int
	 */
	public static function timestamp_to_seconds( string $timestamp ): int {
		$timestamp = trim( $timestamp );

		if ( '' === $timestamp ) {
			return 0;
		}

		if ( is_numeric( $timestamp ) ) {
			return (int) $timestamp;
		}

		$parts = array_map( 'intval', explode( ':', $timestamp ) );
		$count = count( $parts );

		if ( 2 === $count ) {
			return $parts[0] * 60 + $parts[1];
		}

		if ( 3 === $count ) {
			return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
		}

		return 0;
	}

	/**
	 * Convert a duration string ("48:12" or "2892") to seconds.
	 *
	 * @param string $duration Duration string.
	 * @return int
	 */
	public static function duration_to_seconds( string $duration ): int {
		return self::timestamp_to_seconds( $duration );
	}

	/**
	 * Format seconds as H:MM:SS or M:SS.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
		}

		return sprintf( '%d:%02d', $minutes, $secs );
	}
}
