<?php
/**
 * Maps parsed feed items onto episodes (Layer 1).
 *
 * Used by the one-time import (ImportJob) and the recurring host sync
 * (Hosting::sync). Rules:
 *
 * - Identity is the item GUID, stored as the episode's immutable GUID, so
 *   a show moved to this site keeps its GUIDs and podcast apps never see
 *   duplicates.
 * - Local edits win: for every field the importer remembers a hash of what
 *   it last wrote. A later sync only overwrites a field whose current value
 *   still matches that hash; anything edited on this site is kept.
 * - Fields the importer never wrote are only filled when empty, so an
 *   episode created on this site is never overwritten by a matching item.
 * - Media stays on the host (audio and image URLs) unless "copy media" is
 *   chosen, which downloads audio and episode images into the Media
 *   Library (moving a show to this site).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Importer {

	/**
	 * Options.
	 *
	 * @var array{feed_url: string, status: string, download_media: bool, fetch_extras: bool}
	 */
	private array $options;

	/**
	 * GUID => episode ID, built once per run.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $guid_map = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $options feed_url, status (publish|draft),
	 *                                      download_media, fetch_extras.
	 */
	public function __construct( array $options = [] ) {
		$this->options = [
			'feed_url'       => (string) ( $options['feed_url'] ?? '' ),
			'status'         => 'draft' === ( $options['status'] ?? '' ) ? 'draft' : 'publish',
			'download_media' => ! empty( $options['download_media'] ),
			'fetch_extras'   => ! isset( $options['fetch_extras'] ) || ! empty( $options['fetch_extras'] ),
		];
	}

	/**
	 * GUID => episode ID for every episode (any status, including trash,
	 * so an episode deleted here is not re-created by the next sync).
	 *
	 * @return array<string, int>
	 */
	public function guid_map(): array {
		if ( null !== $this->guid_map ) {
			return $this->guid_map;
		}

		global $wpdb;

		// One query instead of a meta_query per item.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk lookup, cached per run.
			$wpdb->prepare(
				"SELECT pm.meta_value AS guid, pm.post_id AS id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s",
				Episodes::META_PREFIX . 'guid',
				EpisodePostType::CPT
			)
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->guid ] = (int) $row->id;
		}

		$this->guid_map = $map;

		return $map;
	}

	/**
	 * Load every known episode and its meta in two queries, so checking a
	 * long feed for changes does not cost queries per item.
	 *
	 * @return void
	 */
	public function prime(): void {
		$ids = array_values( array_unique( $this->guid_map() ) );
		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	/**
	 * Drop items whose GUID already appeared earlier in the feed. Apple
	 * ignores duplicate-GUID episodes too; the first one wins.
	 *
	 * @param array<int, array<string, mixed>> $items Items (feed order).
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} Unique items, titles of dropped duplicates.
	 */
	public static function dedupe( array $items ): array {
		$seen   = [];
		$unique = [];
		$dupes  = [];
		foreach ( $items as $item ) {
			$guid = trim( (string) ( $item['guid'] ?? '' ) );
			if ( isset( $seen[ $guid ] ) ) {
				$dupes[] = (string) ( $item['title'] ?? $guid );
				continue;
			}
			$seen[ $guid ] = true;
			$unique[]      = $item;
		}

		return [ $unique, $dupes ];
	}

	/**
	 * Whether an episode with this GUID exists.
	 *
	 * @param string $guid GUID.
	 * @return bool
	 */
	public function exists( string $guid ): bool {
		return isset( $this->guid_map()[ $guid ] );
	}

	/**
	 * Import or update one parsed item.
	 *
	 * @param array<string, mixed> $item Item from FeedParser.
	 * @return array{action: string, id: int, title: string, message: string}
	 */
	public function import_item( array $item ): array {
		$guid  = trim( (string) ( $item['guid'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );

		$result = [
			'action'  => 'skipped',
			'id'      => 0,
			'title'   => $title,
			'message' => '',
		];

		if ( '' === $guid ) {
			$result['message'] = __( 'The item has no identifier.', 'elementor-podcast-manager' );
			return $result;
		}

		if ( '' === $title ) {
			$title           = __( 'Untitled episode', 'elementor-podcast-manager' );
			$result['title'] = $title;
		}

		$map     = $this->guid_map();
		$post_id = $map[ $guid ] ?? 0;
		$fingerprint = md5( (string) wp_json_encode( $item ) );

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( ! $post || 'trash' === $post->post_status ) {
				$result['id']      = $post_id;
				$result['message'] = __( 'Deleted on this site; not imported again.', 'elementor-podcast-manager' );
				return $result;
			}

			if ( '' !== get_post_meta( $post_id, Episodes::META_PREFIX . 'missing_since', true ) ) {
				delete_post_meta( $post_id, Episodes::META_PREFIX . 'missing_since' );
			}

			// Moving a show that was mirrored before (or a copy that was
			// interrupted): bring the audio over for existing episodes too.
			$copied = false;
			if ( $this->options['download_media'] && (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'audio_id', true ) <= 0 && '' !== (string) ( $item['audio_url'] ?? '' ) ) {
				$problem           = $this->copy_media( $post_id, $item );
				$copied            = '' === $problem;
				$result['message'] = $problem;
				Episodes::clear_data_cache( $post_id );
			}

			// Nothing changed at the host since the last import.
			if ( get_post_meta( $post_id, Episodes::META_PREFIX . 'import_fingerprint', true ) === $fingerprint ) {
				$result['action'] = $copied ? 'updated' : 'unchanged';
				$result['id']     = $post_id;
				return $result;
			}

			$changed = $this->write( $post_id, $item, false );
			update_post_meta( $post_id, Episodes::META_PREFIX . 'import_fingerprint', $fingerprint );

			$result['action'] = ( $changed || $copied ) ? 'updated' : 'unchanged';
			$result['id']     = $post_id;

			return $result;
		}

		$post_id = $this->create( $item, $title, $guid );
		if ( is_wp_error( $post_id ) ) {
			$result['action']  = 'failed';
			$result['message'] = $post_id->get_error_message();
			return $result;
		}

		$this->write( $post_id, $item, true );
		update_post_meta( $post_id, Episodes::META_PREFIX . 'import_fingerprint', $fingerprint );

		if ( $this->options['download_media'] ) {
			$media = $this->copy_media( $post_id, $item );
			if ( '' !== $media ) {
				$result['message'] = $media;
			}
		}

		if ( $this->options['fetch_extras'] || ! empty( $item['chapters'] ) ) {
			$this->fetch_chapters( $post_id, $item );
		}
		if ( $this->options['fetch_extras'] ) {
			$this->fetch_transcript( $post_id, $item );
		}

		Episodes::sync_duration_seconds( $post_id );
		Episodes::clear_data_cache( $post_id );

		$this->guid_map[ $guid ] = $post_id;

		$result['action'] = 'created';
		$result['id']     = $post_id;

		return $result;
	}

	/**
	 * Create the episode post.
	 *
	 * @param array<string, mixed> $item  Item.
	 * @param string               $title Title.
	 * @param string               $guid  GUID.
	 * @return int|\WP_Error
	 */
	private function create( array $item, string $title, string $guid ) {
		$timestamp = (int) ( $item['pub_date'] ?? 0 );
		$status    = $this->options['status'];

		$postarr = [
			'post_type'    => EpisodePostType::CPT,
			'post_title'   => $title,
			'post_content' => self::content_html( (string) ( $item['html'] ?? '' ) ),
			'post_status'  => $status,
			'meta_input'   => [
				// Identity first: the GUID must exist before anything
				// (save_post handlers) could generate a new one.
				Episodes::META_PREFIX . 'guid'   => $guid,
				Episodes::META_PREFIX . 'source' => 'import',
			],
		];

		if ( '' !== $this->options['feed_url'] ) {
			$postarr['meta_input'][ Episodes::META_PREFIX . 'source_feed' ] = $this->options['feed_url'];
		}

		if ( $timestamp > 0 ) {
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );

			// Future dates publish on schedule, like the host would.
			if ( 'publish' === $status && $timestamp > time() ) {
				$postarr['post_status'] = 'future';
			}
		}

		// No usable date: a draft dated now, for someone to check.
		if ( $timestamp <= 0 ) {
			$postarr['post_status'] = 'draft';
		}

		// Hidden from directories at the source (itunes:block): keep it
		// out of this site's feed as well until someone decides.
		if ( ! empty( $item['block'] ) ) {
			$postarr['post_status'] = 'draft';
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		return is_wp_error( $post_id ) ? $post_id : (int) $post_id;
	}

	/**
	 * The values the importer manages, from a feed item.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed> Field => value. Post fields are prefixed "post_".
	 */
	private function values( array $item ): array {
		$explicit = (string) ( $item['explicit'] ?? '' );
		$seconds  = (int) ( $item['duration'] ?? 0 );

		$values = [
			'post_title'        => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'post_content'      => self::content_html( (string) ( $item['html'] ?? '' ) ),
			'short_description' => sanitize_textarea_field( (string) ( $item['summary'] ?? '' ) ),
			'audio_url'         => esc_url_raw( (string) ( $item['audio_url'] ?? '' ) ),
			'audio_type'        => sanitize_text_field( (string) ( $item['audio_type'] ?? '' ) ),
			'audio_length'      => max( 0, (int) ( $item['audio_length'] ?? 0 ) ),
			'duration'          => $seconds > 0 ? Episodes::format_duration( $seconds ) : '',
			'episode_number'    => (int) ( $item['episode'] ?? 0 ) > 0 ? (int) $item['episode'] : '',
			'season_number'     => (int) ( $item['season'] ?? 0 ) > 0 ? (int) $item['season'] : '',
			'episode_type'      => in_array( $item['episode_type'] ?? '', [ 'full', 'trailer', 'bonus' ], true ) ? $item['episode_type'] : 'full',
			'explicit'          => in_array( $explicit, [ 'explicit', 'clean' ], true ) ? $explicit : 'inherit',
			'artwork_url'       => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
			'source_link'       => esc_url_raw( (string) ( $item['link'] ?? '' ) ),
			'guest_name'        => self::first_person( (array) ( $item['persons'] ?? [] ), 'guest' ),
		];

		if ( '' === $values['post_title'] ) {
			unset( $values['post_title'] );
		}

		return $values;
	}

	/**
	 * Show notes as safe HTML. Plain-text notes (Megaphone, SoundCloud and
	 * others send them) get paragraphs and clickable links.
	 *
	 * @param string $html Notes from the feed.
	 * @return string
	 */
	public static function content_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		if ( wp_strip_all_tags( $html ) === $html ) {
			$html = make_clickable( wpautop( esc_html( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		}

		return trim( wp_kses_post( $html ) );
	}

	/**
	 * Name of the first podcast:person with a role.
	 *
	 * @param array<int, array<string, string>> $persons Persons.
	 * @param string                            $role    Role.
	 * @return string
	 */
	public static function first_person( array $persons, string $role ): string {
		foreach ( $persons as $person ) {
			if ( ( $person['role'] ?? '' ) === $role ) {
				return sanitize_text_field( (string) ( $person['name'] ?? '' ) );
			}
		}

		return '';
	}

	/**
	 * Current value of a managed field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field.
	 * @return mixed
	 */
	private function current( int $post_id, string $field ) {
		if ( 0 === strpos( $field, 'post_' ) ) {
			$post = get_post( $post_id );

			return $post ? (string) $post->{$field} : '';
		}

		return get_post_meta( $post_id, Episodes::META_PREFIX . $field, true );
	}

	/**
	 * Stable hash of a field value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function hash( $value ): string {
		return md5( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
	}

	/**
	 * Write managed fields, respecting local edits.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $item    Item.
	 * @param bool                 $is_new  Whether the episode was just created.
	 * @return bool Whether anything changed.
	 */
	private function write( int $post_id, array $item, bool $is_new ): bool {
		$hashes = get_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', true );
		$hashes = is_array( $hashes ) ? $hashes : [];

		$values      = $this->values( $item );
		$post_update = [];
		$owned       = []; // Fields whose value now comes from the host.
		$changed     = false;

		foreach ( $values as $field => $value ) {
			$current = $this->current( $post_id, $field );

			if ( ! $is_new ) {
				if ( self::hash( $current ) === self::hash( $value ) ) {
					$owned[] = $field; // Already identical.
					continue;
				}

				$untouched = isset( $hashes[ $field ] )
					? self::hash( $current ) === $hashes[ $field ]
					: ( '' === $current || null === $current || false === $current );

				if ( ! $untouched ) {
					continue; // Edited on this site: keep it.
				}
			}

			if ( 0 === strpos( $field, 'post_' ) ) {
				// The post was created with these values; only updates write.
				if ( ! $is_new ) {
					$post_update[ $field ] = $value;
				}
			} elseif ( '' === $value ) {
				delete_post_meta( $post_id, Episodes::META_PREFIX . $field );
			} else {
				update_post_meta( $post_id, Episodes::META_PREFIX . $field, is_string( $value ) ? wp_slash( $value ) : $value );
			}

			$owned[] = $field;
			$changed = true;
		}

		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $post_id;
			wp_update_post( wp_slash( $post_update ) );
		}

		// Remember what the site holds now (after sanitizers ran), so the
		// next sync can tell importer-written values from local edits.
		foreach ( $owned as $field ) {
			$hashes[ $field ] = self::hash( $this->current( $post_id, $field ) );
		}
		update_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', $hashes );

		if ( $changed ) {
			Episodes::sync_duration_seconds( $post_id );
			Episodes::clear_data_cache( $post_id );
		}

		return $changed;
	}

	/**
	 * Move imported episodes the host no longer lists back to drafts.
	 *
	 * Hosts often cap how many items a feed carries, so only episodes
	 * published within the feed's time window count as removed.
	 *
	 * @param array<string, bool> $guids  GUIDs present in the feed.
	 * @param int                 $oldest Oldest item date in the feed.
	 * @return int Episodes moved to drafts.
	 */
	public function draft_missing( array $guids, int $oldest ): int {
		if ( '' === $this->options['feed_url'] || empty( $guids ) ) {
			return 0;
		}

		$ids = get_posts(
			[
				'post_type'      => EpisodePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => Episodes::META_PREFIX . 'source_feed',
						'value' => $this->options['feed_url'],
					],
				],
			]
		);

		$count = 0;
		foreach ( $ids as $id ) {
			$guid = (string) get_post_meta( (int) $id, Episodes::META_PREFIX . 'guid', true );
			if ( '' === $guid || isset( $guids[ $guid ] ) ) {
				continue;
			}
			if ( $oldest > 0 && (int) get_post_time( 'U', true, (int) $id ) < $oldest ) {
				continue;
			}
			// Only after it stayed missing for a day: a host's hiccup or a
			// truncated response must not unpublish anything.
			$since = (int) get_post_meta( (int) $id, Episodes::META_PREFIX . 'missing_since', true );
			if ( 0 === $since ) {
				update_post_meta( (int) $id, Episodes::META_PREFIX . 'missing_since', time() );
				continue;
			}
			if ( time() - $since < DAY_IN_SECONDS ) {
				continue;
			}
			delete_post_meta( (int) $id, Episodes::META_PREFIX . 'missing_since' );
			wp_update_post(
				[
					'ID'          => (int) $id,
					'post_status' => 'draft',
				]
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Apply channel metadata to the podcast settings.
	 *
	 * The source's <link> (usually the old host's show page) is never copied
	 * into the website address, nor itunes:block or itunes:new-feed-url.
	 *
	 * @param array<string, mixed> $channel   Channel from FeedParser.
	 * @param bool                 $overwrite Replace values already set here.
	 * @param bool                 $artwork   Copy the show artwork into the Media Library.
	 * @param string               $feed_url  Source feed address (keeps the show's podcast:guid when it moves).
	 * @return array<int, string> Settings keys that changed.
	 */
	public static function apply_channel( array $channel, bool $overwrite, bool $artwork = true, string $feed_url = '' ): array {
		$settings = epm()->settings;
		$current  = $settings->all();
		$next     = $current;

		// Never saved before (a fresh setup): take everything from the feed.
		$stored = get_option( PodcastSettings::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$overwrite = true;
		}

		$category    = '';
		$subcategory = '';
		foreach ( (array) ( $channel['categories'] ?? [] ) as $pair ) {
			if ( Categories::is_valid( (string) ( $pair[0] ?? '' ) ) ) {
				$category    = (string) $pair[0];
				$subcategory = Categories::is_valid_subcategory( $category, (string) ( $pair[1] ?? '' ) ) ? (string) $pair[1] : '';
				break;
			}
		}

		$funding = (array) ( $channel['funding'][0] ?? [] );

		$map = [
			'title'             => (string) ( $channel['title'] ?? '' ),
			'description'       => wp_kses_post( (string) ( $channel['description_html'] ?? '' ) ),
			'short_description' => (string) ( $channel['subtitle'] ?? '' ),
			'author'            => (string) ( $channel['author'] ?? '' ),
			'owner_name'        => (string) ( $channel['owner_name'] ?? '' ),
			'owner_email'       => (string) ( $channel['owner_email'] ?? '' ),
			'copyright'         => (string) ( $channel['copyright'] ?? '' ),
			'language'          => self::locale_from_rss( (string) ( $channel['language'] ?? '' ) ),
			'host'              => self::first_person( (array) ( $channel['persons'] ?? [] ), 'host' ),
			'category'          => $category,
			'funding_url'       => esc_url_raw( (string) ( $funding['url'] ?? '' ) ),
			'funding_label'     => (string) ( $funding['label'] ?? '' ),
		];

		$defaults = PodcastSettings::defaults();
		$changed  = [];

		foreach ( $map as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$is_empty = '' === (string) ( $current[ $key ] ?? '' ) || ( $current[ $key ] ?? '' ) === ( $defaults[ $key ] ?? null );
			if ( $overwrite || $is_empty ) {
				if ( ( $current[ $key ] ?? '' ) !== $value ) {
					$next[ $key ] = $value;
					$changed[]    = $key;
				}
			}
		}

		// The subcategory belongs to its category: set them as a pair.
		if ( in_array( 'category', $changed, true ) || ( '' !== $category && $category === $current['category'] && ( $overwrite || '' === (string) $current['subcategory'] ) ) ) {
			if ( (string) $current['subcategory'] !== $subcategory ) {
				$next['subcategory'] = $subcategory;
				$changed[]           = 'subcategory';
			}
		}

		// Explicit and show type have no "empty" value: only replaced when
		// the feed's details should win.
		if ( $overwrite && in_array( (string) ( $channel['explicit'] ?? '' ), [ 'explicit', 'clean' ], true ) ) {
			if ( $current['explicit'] !== $channel['explicit'] ) {
				$next['explicit'] = (string) $channel['explicit'];
				$changed[]        = 'explicit';
			}
		}

		if ( $overwrite ) {
			$type = 'serial' === ( $channel['type'] ?? '' ) ? 'serial' : 'episodic';
			if ( $current['type'] !== $type ) {
				$next['type'] = $type;
				$changed[]    = 'type';
			}
		}

		if ( $artwork && '' !== (string) ( $channel['image'] ?? '' ) && ( $overwrite || (int) $current['artwork_id'] <= 0 ) ) {
			$attachment = self::sideload_image( (string) $channel['image'], 0, (string) ( $channel['title'] ?? '' ) );
			if ( $attachment > 0 && $attachment !== (int) $current['artwork_id'] ) {
				$next['artwork_id'] = $attachment;
				$changed[]          = 'artwork_id';
			}
		}

		if ( ! empty( $changed ) ) {
			// Run through the regular sanitizer so imported values obey the
			// same rules as the settings form.
			update_option( PodcastSettings::OPTION, $settings->sanitize( $next ) );
		}

		// Keep the show's podcast:guid when it moves here. Without the tag,
		// derive it from the source feed address the way Podcast Index did.
		$guid = strtolower( trim( (string) ( $channel['podcast_guid'] ?? '' ) ) );
		if ( '' === $guid && '' !== $feed_url ) {
			$guid = Feed::uuid_v5( Feed::GUID_NAMESPACE, rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $feed_url ), '/' ) );
		}
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid ) && ( $overwrite || '' === (string) get_option( Feed::GUID_OPTION, '' ) ) ) {
			update_option( Feed::GUID_OPTION, $guid, false );
		}

		return array_values( array_unique( $changed ) );
	}

	/**
	 * RSS language tag ("en-us") to a WordPress-style locale ("en_US").
	 *
	 * @param string $tag Language tag.
	 * @return string
	 */
	public static function locale_from_rss( string $tag ): string {
		$tag = trim( $tag );

		if ( ! preg_match( '/^([a-zA-Z]{2,3})(?:[-_]([a-zA-Z]{2,4}))?/', $tag, $m ) ) {
			return '';
		}

		$locale = strtolower( $m[1] );
		if ( ! empty( $m[2] ) ) {
			$locale .= '_' . ( 2 === strlen( $m[2] ) ? strtoupper( $m[2] ) : ucfirst( strtolower( $m[2] ) ) );
		}

		return $locale;
	}

	/**
	 * Download audio and the episode image into the Media Library.
	 *
	 * @param int                  $post_id Episode ID.
	 * @param array<string, mixed> $item    Item.
	 * @return string Problem description, or '' when everything was copied.
	 */
	private function copy_media( int $post_id, array $item ): string {
		$problems = [];

		$audio_url = (string) ( $item['audio_url'] ?? '' );
		if ( '' !== $audio_url && (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'audio_id', true ) <= 0 ) {
			$attachment = self::sideload( $audio_url, $post_id, (string) ( $item['title'] ?? '' ), 'audio' );
			if ( is_wp_error( $attachment ) ) {
				$problems[] = sprintf(
					/* translators: %s: error message */
					__( 'Audio stays on the host: %s', 'elementor-podcast-manager' ),
					$attachment->get_error_message()
				);
			} else {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'audio_id', $attachment );
				$detected = AudioMetadata::detect( $attachment );
				if ( $detected['size'] > 0 ) {
					update_post_meta( $post_id, Episodes::META_PREFIX . 'audio_size', $detected['size'] );
				}
				if ( '' === (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'duration', true ) && '' !== $detected['duration'] ) {
					update_post_meta( $post_id, Episodes::META_PREFIX . 'duration', $detected['duration'] );
				}
			}
		}

		$image = (string) ( $item['image'] ?? '' );
		if ( '' !== $image && (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'artwork_id', true ) <= 0 ) {
			$attachment = self::sideload_image( $image, $post_id, (string) ( $item['title'] ?? '' ) );
			if ( $attachment > 0 ) {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'artwork_id', $attachment );
			}
		}

		return implode( ' ', $problems );
	}

	/**
	 * Copy an image into the Media Library once (re-used by source URL).
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Parent post (0 for none).
	 * @param string $title   Title for the attachment.
	 * @return int Attachment ID or 0.
	 */
	public static function sideload_image( string $url, int $post_id, string $title ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return 0;
		}

		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_epm_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$attachment = self::sideload( $url, $post_id, $title, 'image' );

		return is_wp_error( $attachment ) ? 0 : $attachment;
	}

	/**
	 * Download a remote file into the Media Library.
	 *
	 * Handles extension-less URLs (common for CDN and tracking links) by
	 * naming the file after its detected type.
	 *
	 * @param string $url     Remote URL.
	 * @param int    $post_id Parent post.
	 * @param string $title   Attachment title.
	 * @param string $kind    audio|image|transcript.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function sideload( string $url, int $post_id, string $title, string $kind ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$timeout = 'audio' === $kind ? 900 : 60;

		// Transcript files are only copied when the URL names their format:
		// their content cannot be sniffed reliably.
		if ( 'transcript' === $kind && ! in_array( Transcripts::mime( $url ), [ 'text/vtt', 'application/x-subrip' ], true ) ) {
			return new \WP_Error( 'epm_transcript_type', __( 'Only WebVTT (.vtt) and SRT (.srt) transcript files are copied.', 'elementor-podcast-manager' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		}

		$tmp = download_url( $url, $timeout );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( wp_basename( $path ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		$allowed = [
			'audio'      => array_keys( AudioMetadata::allowed_mimes() ),
			'image'      => [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ],
			'transcript' => [ 'vtt', 'srt' ],
		][ $kind ] ?? [];

		if ( ! in_array( $ext, $allowed, true ) ) {
			$detected = '';
			if ( 'image' === $kind && function_exists( 'wp_get_image_mime' ) ) {
				$detected = (string) wp_get_image_mime( $tmp );
			} elseif ( function_exists( 'mime_content_type' ) ) {
				$detected = (string) mime_content_type( $tmp );
			}
			$by_mime = [
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
				'audio/mpeg' => 'mp3',
				'audio/mp4'  => 'm4a',
				'audio/x-m4a' => 'm4a',
				'video/mp4'  => 'm4a',
				'audio/wav'  => 'wav',
				'audio/x-wav' => 'wav',
			];
			$ext = $by_mime[ $detected ] ?? ( 'audio' === $kind ? 'mp3' : 'jpg' );
			$base = '' !== $name ? pathinfo( $name, PATHINFO_FILENAME ) : sanitize_title( $title );
			$name = ( '' !== $base ? $base : 'podcast-' . $kind ) . '.' . $ext;
		}

		$attachment = media_handle_sideload(
			[
				'name'     => $name,
				'tmp_name' => $tmp,
			],
			$post_id,
			'' !== $title ? $title : null
		);

		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $tmp );
			return $attachment;
		}

		update_post_meta( (int) $attachment, '_epm_source_url', $url );

		return (int) $attachment;
	}

	/**
	 * Small remote text document (chapters, transcripts).
	 *
	 * @param string $url   URL.
	 * @param int    $bytes Size cap.
	 * @return string Body or '' on failure.
	 */
	private static function fetch_text( string $url, int $bytes ): string {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 15,
				'redirection'         => 5,
				'limit_response_size' => $bytes,
				'user-agent'          => 'ElementorPodcastManager/' . EPM_VERSION . '; ' . home_url( '/' ),
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Import Podcasting 2.0 JSON chapters when the episode has none.
	 *
	 * @param int                  $post_id Episode ID.
	 * @param array<string, mixed> $item    Item.
	 * @return void
	 */
	private function fetch_chapters( int $post_id, array $item ): void {
		if ( ! empty( get_post_meta( $post_id, Episodes::META_PREFIX . 'chapters', true ) ) ) {
			return;
		}

		// Podlove chapters inside the feed item need no request.
		if ( ! empty( $item['chapters'] ) ) {
			$rows = [];
			foreach ( (array) $item['chapters'] as $chapter ) {
				$rows[] = [
					'time'  => Episodes::format_duration( (int) $chapter['start'] ),
					'title' => sanitize_text_field( (string) $chapter['title'] ),
					'url'   => esc_url_raw( (string) $chapter['url'] ),
				];
			}
			update_post_meta( $post_id, Episodes::META_PREFIX . 'chapters', wp_slash( array_slice( $rows, 0, 200 ) ) );
			return;
		}

		$url = (string) ( $item['chapters_url'] ?? '' );
		if ( '' === $url ) {
			return;
		}

		$chapters = self::parse_chapters_json( self::fetch_text( $url, 512 * KB_IN_BYTES ) );
		if ( ! empty( $chapters ) ) {
			update_post_meta( $post_id, Episodes::META_PREFIX . 'chapters', wp_slash( $chapters ) );
		}
	}

	/**
	 * Podcasting 2.0 JSON chapters to the plugin's chapter rows.
	 *
	 * @param string $json JSON document.
	 * @return array<int, array{time: string, title: string, url: string}>
	 */
	public static function parse_chapters_json( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['chapters'] ) || ! is_array( $data['chapters'] ) ) {
			return [];
		}

		$out = [];
		foreach ( $data['chapters'] as $chapter ) {
			if ( ! is_array( $chapter ) || ! isset( $chapter['startTime'] ) || '' === trim( (string) ( $chapter['title'] ?? '' ) ) ) {
				continue;
			}
			if ( isset( $chapter['toc'] ) && false === $chapter['toc'] ) {
				continue;
			}
			$out[] = [
				'time'  => Episodes::format_duration( (int) floor( (float) $chapter['startTime'] ) ),
				'title' => sanitize_text_field( (string) $chapter['title'] ),
				'url'   => esc_url_raw( (string) ( $chapter['url'] ?? '' ) ),
			];
			if ( count( $out ) >= 200 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Import a transcript when the episode has none.
	 *
	 * Prefers HTML, then WebVTT, SRT, JSON and plain text. Timed formats are
	 * turned into readable paragraphs (speaker names kept, timestamps
	 * dropped). A timed file is also kept for the feed's captions.
	 *
	 * @param int                  $post_id Episode ID.
	 * @param array<string, mixed> $item    Item.
	 * @return void
	 */
	private function fetch_transcript( int $post_id, array $item ): void {
		$transcripts = [];
		foreach ( (array) ( $item['transcripts'] ?? [] ) as $transcript ) {
			$url = esc_url_raw( (string) ( $transcript['url'] ?? '' ) );
			if ( '' !== $url ) {
				$transcripts[] = [
					'url'  => $url,
					'type' => Transcripts::normalize_type( (string) ( $transcript['type'] ?? '' ), $url ),
				];
			}
		}
		if ( empty( $transcripts ) ) {
			return;
		}

		$this->keep_transcript_file( $post_id, $transcripts, (string) ( $item['title'] ?? '' ) );

		if ( '' !== trim( (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript', true ) ) ) {
			return;
		}

		$rank = [
			'text/html'            => 0,
			'text/vtt'             => 1,
			'application/x-subrip' => 2,
			'application/json'     => 3,
			'text/plain'           => 4,
		];

		usort(
			$transcripts,
			static function ( $a, $b ) use ( $rank ) {
				return ( $rank[ $a['type'] ] ?? 9 ) <=> ( $rank[ $b['type'] ] ?? 9 );
			}
		);

		$best = $transcripts[0];
		if ( ! isset( $rank[ $best['type'] ] ) ) {
			return;
		}

		$body = self::fetch_text( $best['url'], 2 * MB_IN_BYTES );
		if ( '' === trim( $body ) ) {
			return;
		}

		$html = self::transcript_html( $body, $best['type'] );
		if ( '' !== $html ) {
			update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript', wp_slash( $html ) );
		}
	}

	/**
	 * Keep the host's timed transcript (WebVTT, then SRT, then JSON) so the
	 * feed can list it for captions. Copied into the Media Library along
	 * with the audio, otherwise linked where it is.
	 *
	 * @param int                                           $post_id     Episode ID.
	 * @param array<int, array{url: string, type: string}> $transcripts Normalized transcripts.
	 * @param string                                        $title       Episode title.
	 * @return void
	 */
	private function keep_transcript_file( int $post_id, array $transcripts, string $title ): void {
		if ( (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_file_id', true ) > 0
			|| '' !== (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', true ) ) {
			return;
		}

		$rank = [
			'text/vtt'             => 0,
			'application/x-subrip' => 1,
			'application/json'     => 2,
		];
		$best = null;
		foreach ( $transcripts as $transcript ) {
			if ( isset( $rank[ $transcript['type'] ] ) && ( null === $best || $rank[ $transcript['type'] ] < $rank[ $best['type'] ] ) ) {
				$best = $transcript;
			}
		}
		if ( null === $best ) {
			return;
		}

		if ( $this->options['download_media'] && in_array( Transcripts::mime( $best['url'] ), [ 'text/vtt', 'application/x-subrip' ], true ) ) {
			$attachment = self::sideload( $best['url'], $post_id, $title, 'transcript' );
			if ( ! is_wp_error( $attachment ) ) {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_file_id', $attachment );
				return;
			}
		}

		update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', $best['url'] );
		update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_type', $best['type'] );
	}

	/**
	 * Convert a transcript document to safe HTML.
	 *
	 * @param string $body Document.
	 * @param string $type MIME type.
	 * @return string
	 */
	public static function transcript_html( string $body, string $type ): string {
		if ( 'text/html' === $type ) {
			// Keep the body only.
			if ( preg_match( '#<body[^>]*>(.*)</body>#is', $body, $m ) ) {
				$body = $m[1];
			}
			return trim( wp_kses_post( $body ) );
		}

		if ( 'text/plain' === $type ) {
			return trim( wpautop( esc_html( $body ) ) );
		}

		// Timed formats: group consecutive cues by speaker into paragraphs.
		$cues    = 'application/json' === $type ? self::json_cues( $body ) : self::timed_cues( $body );
		$paras   = [];
		$speaker = '';
		$buffer  = '';

		foreach ( $cues as [ $who, $line ] ) {
			if ( '' !== $who && $who !== $speaker ) {
				if ( '' !== $buffer ) {
					$paras[] = [ $speaker, $buffer ];
				}
				$speaker = $who;
				$buffer  = $line;
			} else {
				$buffer = '' === $buffer ? $line : $buffer . ' ' . $line;
				// Paragraph break every ~600 characters without speakers.
				if ( '' === $speaker && strlen( $buffer ) > 600 && preg_match( '/[.!?]$/', $buffer ) ) {
					$paras[] = [ '', $buffer ];
					$buffer  = '';
				}
			}
		}
		if ( '' !== $buffer ) {
			$paras[] = [ $speaker, $buffer ];
		}

		$html = '';
		foreach ( $paras as [ $who, $text ] ) {
			$html .= '<p>' . ( '' !== $who ? '<strong>' . esc_html( $who ) . ':</strong> ' : '' ) . esc_html( $text ) . "</p>\n";
		}

		return trim( $html );
	}

	/**
	 * Speaker and text of each WebVTT / SRT cue.
	 *
	 * @param string $body Document.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function timed_cues( string $body ): array {
		$body   = str_replace( [ "\r\n", "\r" ], "\n", $body );
		$blocks = preg_split( '/\n{2,}/', $body );
		$cues   = [];

		foreach ( (array) $blocks as $block ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $block ) ), 'strlen' ) );
			$text  = [];
			foreach ( $lines as $line ) {
				if ( 'WEBVTT' === substr( $line, 0, 6 ) || preg_match( '/^(NOTE|STYLE|REGION)\b/', $line ) || ctype_digit( $line ) || false !== strpos( $line, '-->' ) ) {
					continue;
				}
				$text[] = $line;
			}
			if ( empty( $text ) ) {
				continue;
			}

			$line = implode( ' ', $text );
			$who  = '';
			if ( preg_match( '/^<v(?:\.[^\s>]+)?\s+([^>]+)>/', $line, $m ) ) {
				$who = trim( $m[1] );
			} elseif ( preg_match( '/^([\p{L}][\p{L} .\'-]{0,40}):\s/u', $line, $m ) ) {
				$who  = trim( $m[1] );
				$line = substr( $line, strlen( $m[0] ) );
			}

			$cues[] = [ $who, self::cue_text( $line ) ];
		}

		return array_values(
			array_filter(
				$cues,
				static function ( $cue ) {
					return '' !== $cue[1];
				}
			)
		);
	}

	/**
	 * Speaker and text of each Podcasting 2.0 JSON transcript segment.
	 *
	 * @param string $json Document.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function json_cues( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['segments'] ) || ! is_array( $data['segments'] ) ) {
			return [];
		}

		$cues = [];
		foreach ( $data['segments'] as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			$text = self::cue_text( (string) ( $segment['body'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$cues[] = [ sanitize_text_field( (string) ( $segment['speaker'] ?? '' ) ), $text ];
		}

		return $cues;
	}

	/**
	 * Plain text of a cue (voice spans, styling tags and entities removed).
	 *
	 * @param string $line Cue text.
	 * @return string
	 */
	private static function cue_text( string $line ): string {
		return trim( wp_strip_all_tags( html_entity_decode( (string) preg_replace( '/<[^>]+>/', '', $line ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
