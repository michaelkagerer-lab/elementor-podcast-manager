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
	 * Option: GUID rows the upgrade removed (duplicates), for recovery.
	 */
	public const REMOVED_GUIDS_OPTION = 'epm_removed_guid_rows';

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
	}

	/**
	 * One-time idempotent migration: persist immutable GUIDs.
	 *
	 * Episodes that already exist keep their already-issued GUID
	 * byte-for-byte (the legacy home_url-based form), so podcast clients
	 * never see a duplicate. New episodes receive domain-independent
	 * URN GUIDs on save.
	 *
	 * Runs in batches of IDs found with SQL (episodes without a GUID row),
	 * so a large catalog never loads every episode's meta at once.
	 *
	 * @return void
	 */
	public function maybe_migrate_guids(): void {
		global $wpdb;

		if ( get_option( 'epm_guids_migrated', false ) ) {
			return;
		}

		do {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') AND m.meta_id IS NULL ORDER BY p.ID LIMIT 500", self::META_PREFIX . 'guid', EpisodePostType::CPT ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
			foreach ( $ids as $id ) {
				// Preserve the already-issued identity exactly.
				add_post_meta( $id, self::META_PREFIX . 'guid', home_url( '/?epm_episode_guid=' . $id ), true );
			}
		} while ( count( $ids ) === 500 );

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
	 * Remove extra _epm_guid rows. Before 1.4 two requests that read a new
	 * episode at the same time could each store a random GUID. WordPress
	 * returns the first row (the lowest meta_id) to every reader, so that
	 * is the GUID apps and directories saw from then on; it stays, the
	 * others are deleted.
	 *
	 * @param int $limit Episodes per call.
	 * @return int Rows deleted.
	 */
	public static function collapse_guid_rows( int $limit ): int {
		global $wpdb;

		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, MIN(meta_id) AS keep_id FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY post_id HAVING COUNT(*) > 1 LIMIT %d", self::META_PREFIX . 'guid', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upgrade batch.
		$removed = 0;
		$record  = get_option( self::REMOVED_GUIDS_OPTION, [] );
		$record  = is_array( $record ) ? $record : [];
		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;
			$keep    = (int) $row->keep_id;
			$extra   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_id <> %d", $post_id, self::META_PREFIX . 'guid', $keep ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upgrade batch.
			foreach ( $extra as $gone ) {
				// Kept for recovery (MIGRATION.md): which value was removed.
				$record[] = [
					'post_id' => $post_id,
					'guid'    => (string) $gone->meta_value,
					'kept'    => (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $keep ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upgrade batch.
					'time'    => time(),
				];
			}
			$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_id <> %d", $post_id, self::META_PREFIX . 'guid', $keep ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upgrade batch.
			wp_cache_delete( $post_id, 'post_meta' );
			self::clear_data_cache( $post_id );
		}
		if ( $removed > 0 ) {
			update_option( self::REMOVED_GUIDS_OPTION, array_slice( $record, -1000 ), false );
			Feed::flush_cache();
		}

		return $removed;
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
		return self::sync_durations( [ $post_id ] )[ $post_id ] ?? 0;
	}

	/**
	 * sync_duration_seconds() for several episodes: one query for their
	 * durations, a write only where the stored seconds differ.
	 *
	 * @param int[] $ids Episode post IDs.
	 * @return array<int, int> Seconds per episode.
	 */
	public static function sync_durations( array $ids ): array {
		$ids   = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$meta  = self::read_meta( $ids, [ 'duration', 'audio_id', 'duration_seconds' ] );
		$out   = [];
		$files = [];

		foreach ( $ids as $id ) {
			$seconds  = self::duration_to_seconds( (string) $meta[ $id ]['duration'] );
			$audio_id = (int) $meta[ $id ]['audio_id'];

			if ( 0 === $seconds && $audio_id > 0 ) {
				$file    = wp_get_attachment_metadata( $audio_id );
				$seconds = is_array( $file ) && ! empty( $file['length'] ) ? (int) $file['length'] : 0;
				$files[] = $audio_id;
			}

			$stored = $meta[ $id ]['duration_seconds'];
			if ( null === $stored ) {
				add_post_meta( $id, self::META_PREFIX . 'duration_seconds', $seconds, true );
			} elseif ( (string) $seconds !== (string) $stored ) {
				update_post_meta( $id, self::META_PREFIX . 'duration_seconds', $seconds );
			}
			$out[ $id ] = $seconds;
		}

		// Batches (the upgrade) keep no audio file meta in memory.
		if ( count( $ids ) > 1 ) {
			self::release_caches( $files );
		}

		return $out;
	}

	/**
	 * A few meta keys of episodes, read with one query for just these keys
	 * (the first row of each, like get_post_meta()), so an episode's other
	 * meta, transcripts and show notes, is not loaded.
	 *
	 * @param int[]    $ids  Episode post IDs.
	 * @param string[] $keys Keys without the _epm_ prefix.
	 * @return array<int, array<string, mixed>> ID => key => value (null when missing).
	 */
	public static function read_meta( array $ids, array $keys ): array {
		global $wpdb;

		$out = [];
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = array_fill_keys( $keys, null );
		}
		if ( empty( $ids ) || empty( $keys ) ) {
			return $out;
		}

		$names = array_map(
			static function ( $key ) {
				return self::META_PREFIX . $key;
			},
			$keys
		);
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ') ORDER BY meta_id ASC', $names ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers and placeholders.
		foreach ( $rows as $row ) {
			$key = substr( (string) $row->meta_key, strlen( self::META_PREFIX ) );
			$id  = (int) $row->post_id;
			if ( isset( $out[ $id ] ) && array_key_exists( $key, $out[ $id ] ) && null === $out[ $id ][ $key ] ) {
				$out[ $id ][ $key ] = maybe_unserialize( $row->meta_value );
			}
		}

		return $out;
	}

	/**
	 * Free what a page of episodes put into memory (posts, meta, the
	 * normalized data), so working through a large catalog page by page
	 * needs the memory of one page. With a persistent object cache only
	 * its in-memory copy is dropped, never the stored entries.
	 *
	 * @param int[] $ids Post IDs (episodes and attachments).
	 * @return void
	 */
	public static function release_caches( array $ids ): void {
		foreach ( $ids as $id ) {
			unset( self::$data_cache[ (int) $id ] );
		}

		if ( wp_using_ext_object_cache() ) {
			if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			$GLOBALS['wpdb']->flush();
			return;
		}

		foreach ( $ids as $id ) {
			wp_cache_delete( (int) $id, 'posts' );
			wp_cache_delete( (int) $id, 'post_meta' );
		}
		$GLOBALS['wpdb']->flush();
	}

	/**
	 * Clear the per-request data cache (e.g. after metadata changes).
	 *
	 * Reads only the keys it needs, never the episode's whole meta (a
	 * transcript can be megabytes).
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
			$query_args['meta_key'] = self::META_PREFIX . 'episode_number';
			$query_args['orderby']  = 'meta_value_num';
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
