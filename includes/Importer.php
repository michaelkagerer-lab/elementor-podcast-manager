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
 * - Media stays on the host (audio, image and transcript URLs) unless
 *   "copy media" is chosen, which copies the audio, the episode images and
 *   WebVTT/SRT transcript files into the Media Library (moving a show to
 *   this site), each kind on its own and also for episodes that exist
 *   already. Only addresses the importer wrote are replaced; what still
 *   points to the host is reported (old_host_references()).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Importer {
	/** Keep a stable feed identifier without persisting URL credentials. */
	public static function source_feed_identifier( $value ): string {
		$value = (string) $value;
		if ( preg_match( '/^private:[a-f0-9]{64}$/D', $value ) ) {
			return $value;
		}
		return Hosting::has_url_secret( $value ) ? 'private:' . hash( 'sha256', $value ) : esc_url_raw( $value );
	}
	/**
	 * Default post author for non-interactive imports.
	 *
	 * @return int
	 */
	private static function default_author(): int {
		$users = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ] );
		return ! empty( $users ) ? (int) $users[0] : 1;
	}

	/**
	 * Normalize volatile URL parameters used by signed media URLs.
	 *
	 * @param string $url Media URL.
	 * @return string
	 */
	private static function stable_media_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return $url;
		}
		parse_str( (string) $parts['query'], $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( preg_match( '/^(?:x-amz-|x-goog-|expires$|signature$|sig$|token$|auth$|key-pair-id$|policy$|hdnts$)/i', (string) $key ) ) {
				unset( $query[ $key ] );
			}
		}
		$clean = (string) ( $parts['scheme'] ?? 'https' ) . '://' . (string) ( $parts['host'] ?? '' ) . (string) ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . (string) ( $parts['path'] ?? '' );
		$query_string = http_build_query( $query );
		return '' !== $query_string ? $clean . '?' . $query_string : $clean;
	}

	/**
	 * Options.
	 *
	 * @var array{feed_url: string, status: string, download_media: bool, fetch_extras: bool, deadline: float}
	 */
	private array $options;

	/**
	 * GUID => episode ID, built once per run.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $guid_map = null;
	private bool $guid_map_loaded = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $options feed_url, status (publish|draft),
	 *                                      download_media, fetch_extras,
	 *                                      deadline (microtime() by which media
	 *                                      downloads of a call stop; 0: the
	 *                                      default time per request).
	 */
	public function __construct( array $options = [] ) {
		$source_feed = (string) ( $options['feed_url'] ?? '' );
		$this->options = [
			'feed_url'       => $source_feed,
			'status'         => 'draft' === ( $options['status'] ?? '' ) ? 'draft' : 'publish',
			'download_media' => ! empty( $options['download_media'] ),
			'fetch_extras'   => ! isset( $options['fetch_extras'] ) || ! empty( $options['fetch_extras'] ),
			'deadline'       => (float) ( $options['deadline'] ?? 0 ),
			'author'         => max( 0, (int) ( $options['author'] ?? 0 ) ),
		];
	}

	/**
	 * GUID => episode ID for every episode (any status, including trash,
	 * so an episode deleted here is not re-created by the next sync).
	 *
	 * @return array<string, int>
	 */
	public function guid_map(): array {
		if ( $this->guid_map_loaded ) {
			return $this->guid_map;
		}
		$this->guid_map_loaded = true;
		if ( isset( $GLOBALS['epm_import_guid_map'] ) && is_array( $GLOBALS['epm_import_guid_map'] ) ) {
			$this->guid_map = $GLOBALS['epm_import_guid_map'];
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
		if ( ! empty( $GLOBALS['epm_import_step_active'] ) ) {
			$GLOBALS['epm_import_guid_map'] = $map;
		}

		return $map;
	}

	/**
	 * The episode with a GUID, read from the database (0 when none). Any
	 * status, the trash included, like guid_map().
	 *
	 * @param string $guid GUID.
	 * @return int
	 */
	public static function find_guid( string $guid ): int {
		global $wpdb;

		// BINARY: GUIDs are compared byte for byte, like guid_map() does.
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must see what other requests wrote.
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND BINARY pm.meta_value = %s AND p.post_type = %s ORDER BY pm.post_id LIMIT 1",
				Episodes::META_PREFIX . 'guid',
				$guid,
				EpisodePostType::CPT
			)
		);
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
	 * With "copy media", every file of the episode that still loads from
	 * the host is copied, each kind on its own (audio, episode image,
	 * WebVTT/SRT transcript file): also for episodes that exist already
	 * (mirrored before, or a copy that failed earlier). Only addresses the
	 * importer wrote are replaced; an address chosen on this site stays.
	 * A large file may take several calls: the outcome's `pending` holds
	 * where the copy stands, and the next call continues it when it gets
	 * that back as $resume.
	 *
	 * @param array<string, mixed> $item   Item from FeedParser.
	 * @param array<string, mixed> $resume What an earlier call for this item left (action, kind, download, done).
	 * @return array{action: string, id: int, title: string, message: string, media_failed: bool, media: array<string, array<string, string>>, remaining: array<string, string>, pending: array<string, mixed>|null}
	 */
	public function import_item( array $item, array $resume = [] ): array {
		$guid  = trim( (string) ( $item['guid'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );

		$result = [
			'action'       => 'skipped',
			'id'           => 0,
			'title'        => $title,
			'message'      => '',
			// "Copy media" was chosen, but the audio stayed at the host.
			'media_failed' => false,
			// Per kind: what the copy did (copied, failed, busy) and why.
			'media'        => [],
			// Per kind: the address that still points to the host.
			'remaining'    => [],
			// A copy that continues in the next call.
			'pending'      => null,
		];

		if ( '' === $guid ) {
			$result['message'] = __( 'The item has no identifier.', 'elementor-podcast-manager' );
			return $result;
		}

		if ( '' === $title ) {
			$title           = __( 'Untitled episode', 'elementor-podcast-manager' );
			$result['title'] = $title;
		}

		$map         = $this->guid_map();
		$post_id     = $map[ $guid ] ?? 0;
		$fingerprint_item = $item;
		$fingerprint_item['audio_url'] = self::stable_media_url( (string) ( $item['audio_url'] ?? '' ) );
		$fingerprint = md5( (string) wp_json_encode( $fingerprint_item ) );

		// The list was read when this run started: another request may
		// have created the episode since. Ask the database right before
		// creating one, so a GUID never gets a second episode.
		if ( $post_id <= 0 ) {
			$post_id = self::find_guid( $guid );
			if ( $post_id > 0 ) {
				$this->guid_map[ $guid ] = $post_id;
			}
		}

		$changed = false;
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

			// The host's feed moved: re-tag the imported episodes it still
			// lists, so "unpublish episodes the host removed" keeps finding
			// them. Episodes created on this site stay untagged.
			$source_feed = (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'source_feed', true );
			$next_source_feed = self::source_feed_identifier( $this->options['feed_url'] );
			if ( '' !== $next_source_feed && '' !== $source_feed && $source_feed !== $next_source_feed ) {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'source_feed', $next_source_feed );
			}

			// Changed at the host since the last import.
			if ( get_post_meta( $post_id, Episodes::META_PREFIX . 'import_fingerprint', true ) !== $fingerprint ) {
				$changed = $this->write( $post_id, $item, false );
				update_post_meta( $post_id, Episodes::META_PREFIX . 'import_fingerprint', $fingerprint );
			}
			$action = $changed ? 'updated' : 'unchanged';
		} else {
			$post_id = $this->create( $item, $title, $guid );
			if ( is_wp_error( $post_id ) ) {
				$result['action']  = 'failed';
				$result['message'] = $post_id->get_error_message();
				return $result;
			}

			$this->write( $post_id, $item, true );
			update_post_meta( $post_id, Episodes::META_PREFIX . 'import_fingerprint', $fingerprint );
			$this->guid_map[ $guid ] = $post_id;
			if ( ! empty( $GLOBALS['epm_import_step_active'] ) ) {
				$GLOBALS['epm_import_guid_map'] = $this->guid_map;
			}
			$action                  = 'created';
		}

		// A copy that took several calls is one outcome: the first call's.
		if ( 'unchanged' === $action && in_array( $resume['action'] ?? '', [ 'created', 'updated' ], true ) ) {
			$action = (string) $resume['action'];
		}
		$result['id'] = $post_id;

		// Chapters and transcripts before any copy, so an interrupted copy
		// never loses them. The marker is set when the episode is created
		// and removed once they are fetched: an episode whose import died
		// before that gets them on the next run.
		if ( '' !== (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'import_extras', true ) ) {
			if ( $this->options['fetch_extras'] || ! empty( $item['chapters'] ) ) {
				$this->fetch_chapters( $post_id, $item );
			}
			if ( $this->options['fetch_extras'] ) {
				$this->fetch_transcript( $post_id, $item );
			}
			delete_post_meta( $post_id, Episodes::META_PREFIX . 'import_extras' );
			if ( 'unchanged' === $action ) {
				$action = 'updated';
			}
		}

		// Where the transcript address came from, for episodes imported
		// before that was recorded.
		$this->record_transcript_provenance( $post_id, $item );

		if ( $this->options['download_media'] ) {
			$copy              = $this->copy_files( $post_id, $item, $resume );
			$result['media']   = $copy['media'];
			$result['pending'] = $copy['pending'];
			if ( $copy['copied'] && 'unchanged' === $action ) {
				$action = 'updated';
			}

			$result['remaining']    = self::old_host_references( $post_id, $item );
			$result['media_failed'] = isset( $result['remaining']['audio'] );
			$result['message']      = self::media_message( $result['media'] );
		}

		if ( 'unchanged' !== $action ) {
			Episodes::sync_duration_seconds( $post_id );
			Episodes::clear_data_cache( $post_id );
		}

		$result['action'] = $action;

		return $result;
	}

	/**
	 * The problems of a copy in words, for the import log.
	 *
	 * @param array<string, array<string, string>> $media Per kind: state and reason.
	 * @return string
	 */
	private static function media_message( array $media ): string {
		$labels = [
			/* translators: %s: why the file was not copied */
			'audio'           => __( 'Audio stays on the host: %s', 'elementor-podcast-manager' ),
			/* translators: %s: why the file was not copied */
			'image'           => __( 'Episode image stays on the host: %s', 'elementor-podcast-manager' ),
			/* translators: %s: why the file was not copied */
			'transcript_file' => __( 'Transcript file stays on the host: %s', 'elementor-podcast-manager' ),
		];

		$out = [];
		foreach ( $labels as $kind => $label ) {
			if ( in_array( $media[ $kind ]['state'] ?? '', [ 'failed', 'busy' ], true ) ) {
				$out[] = sprintf( $label, (string) $media[ $kind ]['reason'] );
			}
		}

		return implode( ' ', $out );
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
			'post_author'  => $this->options['author'] > 0 ? $this->options['author'] : self::default_author(),
			'meta_input'   => [
				// Identity first: the GUID must exist before anything
				// (save_post handlers) could generate a new one.
				Episodes::META_PREFIX . 'guid'          => $guid,
				Episodes::META_PREFIX . 'source'        => 'import',
				// Chapters and transcripts still to fetch (see import_item()).
				Episodes::META_PREFIX . 'import_extras' => 1,
			],
		];

		if ( '' !== $this->options['feed_url'] ) {
			$postarr['meta_input'][ Episodes::META_PREFIX . 'source_feed' ] = self::source_feed_identifier( $this->options['feed_url'] );
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
		$date     = (int) ( $item['pub_date'] ?? 0 );

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
			'post_date'         => $date > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $date ) ) : '',
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
	 * Saving an episode in an editor changes the bytes without changing the
	 * content: textareas send CRLF line breaks, and the classic editor
	 * stores show notes without the <p> tags the importer wrote. Both are
	 * normalized away, so such a save does not count as a local edit.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field the value belongs to.
	 * @return string
	 */
	private static function hash( $value, string $field = '' ): string {
		if ( ! is_scalar( $value ) ) {
			return md5( (string) wp_json_encode( $value ) );
		}

		$value = str_replace( [ "\r\n", "\r" ], "\n", (string) $value );

		if ( 'post_content' === $field ) {
			$value = wpautop( $value );
			$value = (string) preg_replace( '/\s+/', ' ', $value );
			$value = trim( (string) preg_replace( '#\s*(</?(?:p|br|ul|ol|li|h[1-6]|blockquote|div|pre|table|tr|td|th)\b[^>]*>)\s*#i', '$1', $value ) );
		}
		if ( 'audio_url' === $field ) {
			$value = self::stable_media_url( (string) $value );
		}
		if ( 'post_date' === $field ) {
			$value = str_replace( ' ', 'T', (string) $value );
		}

		return md5( $value );
	}

	/**
	 * The hash earlier versions stored (raw bytes), still accepted as
	 * "written by the importer" for episodes imported before.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function legacy_hash( $value ): string {
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
				if ( self::hash( $current, $field ) === self::hash( $value, $field ) ) {
					$owned[] = $field; // Already identical.
					continue;
				}

				$untouched = isset( $hashes[ $field ] )
					? in_array( $hashes[ $field ], [ self::hash( $current, $field ), self::legacy_hash( $current ) ], true )
					: ( '' === $current || null === $current || false === $current );
				// Import hashes account for the site's timezone conversion.
				if ( 'post_date' === $field && isset( $hashes[ $field ] ) && self::hash( $current, $field ) === $hashes[ $field ] ) {
					$untouched = true;
				}

				if ( ! $untouched ) {
					continue; // Edited on this site: keep it.
				}
			}

			// A published import whose host date changes must move between
			// published and scheduled status along with its managed date.
			if ( ! $is_new && 'post_date' === $field && '' !== $value && ! isset( $post_update['post_status'] ) ) {
				$timestamp = strtotime( (string) $value );
				if ( $timestamp && in_array( get_post_status( $post_id ), [ 'publish', 'future' ], true ) ) {
					$post_update['post_status'] = $timestamp > current_time( 'timestamp' ) ? 'future' : 'publish';
				}
			}

			if ( 0 === strpos( $field, 'post_' ) ) {
				// The post was created with these values; only updates write.
				if ( ! $is_new && ( '' !== $value || 'post_date' === $field ) ) {
					if ( 'post_date' === $field ) {
						$post_update['post_date']     = $value;
						$post_update['post_date_gmt'] = get_gmt_from_date( $value );
					} else {
						$post_update[ $field ] = $value;
					}
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
			$hashes[ $field ] = self::hash( $this->current( $post_id, $field ), $field );
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
	public function draft_missing( array $guids, int $oldest, bool $future_only = false ): int {
		if ( '' === $this->options['feed_url'] || empty( $guids ) ) {
			return 0;
		}

		$ids = get_posts(
			[
				'post_type'      => EpisodePostType::CPT,
			'post_status'    => [ 'publish', 'future' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => Episodes::META_PREFIX . 'source_feed',
						'value' => self::source_feed_identifier( $this->options['feed_url'] ),
					],
				],
			]
		);

		$count = 0;
		foreach ( $ids as $id ) {
			$is_future = 'future' === get_post_status( (int) $id );
			if ( $future_only && ! $is_future ) {
				continue;
			}
			$guid = (string) get_post_meta( (int) $id, Episodes::META_PREFIX . 'guid', true );
			if ( '' === $guid || isset( $guids[ $guid ] ) ) {
				continue;
			}
			if ( ! $is_future && $oldest > 0 && (int) get_post_time( 'U', true, (int) $id ) < $oldest ) {
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

		self::adopt_podcast_guid( $channel, $feed_url, $overwrite );

		return array_values( array_unique( $changed ) );
	}

	/**
	 * Keep the show's podcast:guid when it moves here. Without the tag, it
	 * is derived from the source feed address the way Podcast Index did.
	 *
	 * @param array<string, mixed> $channel  Channel from FeedParser.
	 * @param string               $feed_url Source feed address ('' for none).
	 * @param bool                 $force    Replace a guid already stored here.
	 * @return void
	 */
	public static function adopt_podcast_guid( array $channel, string $feed_url, bool $force ): void {
		$guid = strtolower( trim( (string) ( $channel['podcast_guid'] ?? '' ) ) );
		if ( '' === $guid && '' !== $feed_url ) {
			$guid = Feed::uuid_v5( Feed::GUID_NAMESPACE, rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $feed_url ), '/' ) );
		}
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid ) && ( $force || '' === (string) get_option( Feed::GUID_OPTION, '' ) ) ) {
			update_option( Feed::GUID_OPTION, $guid, false );
		}
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
	 * Whether another request is downloading this episode's audio right
	 * now (a marker younger than the longest audio download).
	 *
	 * @param int $post_id Episode ID.
	 * @return bool
	 */
	private static function copy_in_progress( int $post_id ): bool {
		$since = (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'copying', true );

		return $since > 0 && time() - $since < 17 * MINUTE_IN_SECONDS;
	}

	/**
	 * The kinds of files a move copies, in the order they are copied (small
	 * files first, so a long audio download never holds them up).
	 */
	private const COPY_KINDS = [ 'transcript_file', 'image', 'audio' ];

	/**
	 * Copy every file of an episode that still loads from the host, each
	 * kind on its own; a failure of one kind never keeps another from being
	 * copied.
	 *
	 * @param int                  $post_id Episode ID.
	 * @param array<string, mixed> $item    Item.
	 * @param array<string, mixed> $resume  Where an earlier call stopped (kind, download, done).
	 * @return array{media: array<string, array<string, string>>, pending: array<string, mixed>|null, copied: bool}
	 */
	private function copy_files( int $post_id, array $item, array $resume ): array {
		$out = [
			// Kinds settled by an earlier call for this item are not tried again.
			'media'   => (array) ( $resume['done'] ?? [] ),
			'pending' => null,
			'copied'  => false,
		];
		$title    = (string) ( $item['title'] ?? '' );
		$deadline = $this->options['deadline'] > 0 ? $this->options['deadline'] : microtime( true ) + MediaDownload::request_seconds();

		foreach ( self::COPY_KINDS as $kind ) {
			if ( isset( $out['media'][ $kind ] ) ) {
				continue;
			}
			$url = self::copy_source( $post_id, $kind );
			if ( '' === $url ) {
				continue;
			}

			// Another request downloads this audio right now (a run of its
			// own; never the copy this job continues, or one of its own
			// requests that died).
			$ours = 'audio' === ( $resume['kind'] ?? '' ) || (int) ( $resume['tries'] ?? 0 ) > 0;
			if ( 'audio' === $kind && ! $ours && self::copy_in_progress( $post_id ) ) {
				$out['media'][ $kind ] = [
					'state'  => 'busy',
					'url'    => $url,
					'reason' => __( 'Another request is copying this file right now.', 'elementor-podcast-manager' ),
				];
				continue;
			}

			// No time left in this request: the next one starts this file.
			if ( $deadline - microtime( true ) < 1.0 ) {
				$out['pending'] = [
					'kind'     => $kind,
					'download' => [],
				];
				break;
			}

			if ( 'audio' === $kind ) {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'copying', time() );
			}
			$copy = MediaCopy::copy(
				$url,
				'transcript_file' === $kind ? 'transcript' : $kind,
				$post_id,
				$title,
				[
					'deadline' => $deadline,
					'download' => ( $resume['kind'] ?? '' ) === $kind ? (array) ( $resume['download'] ?? [] ) : [],
					'hint'     => 'audio' === $kind ? (int) get_post_meta( $post_id, Episodes::META_PREFIX . 'audio_length', true ) : 0,
				]
			);
			if ( 'audio' === $kind ) {
				delete_post_meta( $post_id, Episodes::META_PREFIX . 'copying' );
			}

			if ( 'copied' === $copy['result'] ) {
				$this->apply_copy( $post_id, $kind, (int) $copy['attachment'] );
				$out['media'][ $kind ] = [
					'state' => 'copied',
					'url'   => $url,
				];
				$out['copied']         = true;
				continue;
			}

			if ( 'failed' === $copy['result'] ) {
				$out['media'][ $kind ] = [
					'state'  => 'failed',
					'url'    => $url,
					'code'   => (string) $copy['reason'],
					'reason' => (string) $copy['message'],
				];
				continue;
			}

			// Not finished in this request (or the host asked to wait).
			$out['pending'] = [
				'kind'     => $kind,
				'download' => (array) $copy['download'],
				'until'    => (int) $copy['until'],
				'reason'   => (string) $copy['message'],
			];
			break;
		}

		if ( $out['copied'] ) {
			Episodes::clear_data_cache( $post_id );
		}

		return $out;
	}

	/**
	 * The address to copy a kind of file from ('' when there is nothing to
	 * copy: the file is local already, the episode has none, the address was
	 * chosen on this site, or the format is not copied).
	 *
	 * @param int    $post_id Episode ID.
	 * @param string $kind    audio, image or transcript_file.
	 * @return string
	 */
	private static function copy_source( int $post_id, string $kind ): string {
		$refs = self::old_host_references( $post_id );

		return (string) ( $refs[ $kind ] ?? '' );
	}

	/**
	 * Use a copied file for an episode.
	 *
	 * @param int    $post_id    Episode ID.
	 * @param string $kind       audio, image or transcript_file.
	 * @param int    $attachment Attachment ID.
	 * @return void
	 */
	private function apply_copy( int $post_id, string $kind, int $attachment ): void {
		if ( 'audio' === $kind ) {
			update_post_meta( $post_id, Episodes::META_PREFIX . 'audio_id', $attachment );
			$detected = AudioMetadata::detect( $attachment );
			if ( $detected['size'] > 0 ) {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'audio_size', $detected['size'] );
			}
			if ( '' === (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'duration', true ) && '' !== $detected['duration'] ) {
				update_post_meta( $post_id, Episodes::META_PREFIX . 'duration', $detected['duration'] );
			}
			return;
		}

		if ( 'image' === $kind ) {
			update_post_meta( $post_id, Episodes::META_PREFIX . 'artwork_id', $attachment );
			return;
		}

		// The copy replaces the link: the feed lists the file from this site.
		update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_file_id', $attachment );
		delete_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url' );
		delete_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_type' );
		self::remember( $post_id, 'transcript_url', null );
	}

	/**
	 * Addresses of an episode that still point to the host it was imported
	 * from, per kind:
	 *
	 * - audio:           the audio URL, when no Media Library file is attached;
	 * - image:           the episode image URL, when no image is attached;
	 * - transcript_file: a WebVTT/SRT transcript linked at the host (copyable);
	 * - transcript_link: a transcript in another format linked at the host
	 *                    (JSON, HTML, text: not copied, the link stays).
	 *
	 * Only addresses the importer wrote count. An address set on this site
	 * (a local edit, a deliberately chosen external file) is no dependency on
	 * the old host and is never replaced.
	 *
	 * @param int                       $post_id Episode ID.
	 * @param array<string, mixed>|null $item    Feed item, when known (decides for transcript addresses imported before 1.4).
	 * @return array<string, string> Kind => URL.
	 */
	public static function old_host_references( int $post_id, ?array $item = null ): array {
		$meta = static function ( string $key ) use ( $post_id ): string {
			return (string) get_post_meta( $post_id, Episodes::META_PREFIX . $key, true );
		};
		$out  = [];

		$audio_id = (int) $meta( 'audio_id' );
		if ( ( $audio_id <= 0 || 'attachment' !== get_post_type( $audio_id ) ) && '' !== $meta( 'audio_url' ) && self::owns( $post_id, 'audio_url' ) ) {
			$out['audio'] = $meta( 'audio_url' );
		}

		$artwork_id = (int) $meta( 'artwork_id' );
		if ( ( $artwork_id <= 0 || 'attachment' !== get_post_type( $artwork_id ) ) && ! has_post_thumbnail( $post_id ) && '' !== $meta( 'artwork_url' ) && self::owns( $post_id, 'artwork_url' ) ) {
			$out['image'] = $meta( 'artwork_url' );
		}

		$link = $meta( 'transcript_url' );
		if ( '' !== $link && self::owns_transcript( $post_id, $item ) ) {
			$type   = Transcripts::normalize_type( $meta( 'transcript_type' ), $link );
			$timed  = in_array( $type, [ 'text/vtt', 'application/x-subrip' ], true );
			$file   = (int) $meta( 'transcript_file_id' );
			$listed = ! ( $file > 0 && 'attachment' === get_post_type( $file ) && Transcripts::mime( (string) get_attached_file( $file ) ) === $type );
			if ( $listed ) {
				if ( ! $timed ) {
					$out['transcript_link'] = $link;
				} elseif ( $file <= 0 || 'attachment' !== get_post_type( $file ) ) {
					$out['transcript_file'] = $link;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a field's current value is the one the importer wrote (not
	 * edited on this site).
	 *
	 * @param int    $post_id Episode ID.
	 * @param string $field   Managed meta field.
	 * @return bool
	 */
	private static function owns( int $post_id, string $field ): bool {
		$hashes = get_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', true );
		if ( ! is_array( $hashes ) || ! isset( $hashes[ $field ] ) ) {
			// No record (the importer records every value it writes): an
			// imported episode's address still counts as the import's, so a
			// dependency on the old host is never hidden.
			return 'import' === get_post_meta( $post_id, Episodes::META_PREFIX . 'source', true );
		}
		$value = get_post_meta( $post_id, Episodes::META_PREFIX . $field, true );

		return in_array( $hashes[ $field ], [ self::hash( $value, $field ), self::legacy_hash( $value ) ], true );
	}

	/**
	 * Whether the episode's transcript address is the one the import wrote.
	 *
	 * Recorded since 1.4 (the import hash of transcript_url). For an
	 * address 1.3.0 wrote, nothing was recorded: it counts as the import's
	 * when the feed item lists it, otherwise as chosen on this site. Without
	 * the item (Readiness), such an address of an imported episode counts as
	 * the import's, so a possible dependency is never hidden.
	 *
	 * @param int                       $post_id Episode ID.
	 * @param array<string, mixed>|null $item    Feed item.
	 * @return bool
	 */
	private static function owns_transcript( int $post_id, ?array $item ): bool {
		$url    = (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', true );
		$hashes = get_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', true );
		if ( '' === $url ) {
			return false;
		}
		if ( is_array( $hashes ) && isset( $hashes['transcript_url'] ) ) {
			return self::hash( $url, 'transcript_url' ) === $hashes['transcript_url'];
		}
		if ( null === $item ) {
			return 'import' === get_post_meta( $post_id, Episodes::META_PREFIX . 'source', true );
		}

		return in_array( $url, self::item_transcript_urls( $item ), true );
	}

	/**
	 * Whether an episode's transcript address came with the import (not
	 * chosen on this site), for the editor.
	 *
	 * @param int $post_id Episode ID.
	 * @return bool
	 */
	public static function transcript_from_import( int $post_id ): bool {
		return self::owns_transcript( $post_id, null );
	}

	/**
	 * Record where a transcript address that has no record yet came from:
	 * the import (it is one of the feed item's own), or this site.
	 *
	 * @param int                  $post_id Episode ID.
	 * @param array<string, mixed> $item    Feed item.
	 * @return void
	 */
	private function record_transcript_provenance( int $post_id, array $item ): void {
		$url    = (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', true );
		$hashes = get_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', true );
		if ( '' === $url || ( is_array( $hashes ) && isset( $hashes['transcript_url'] ) ) ) {
			return;
		}

		self::remember( $post_id, 'transcript_url', in_array( $url, self::item_transcript_urls( $item ), true ) ? self::hash( $url, 'transcript_url' ) : 'local' );
	}

	/**
	 * Transcript addresses a feed item lists.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string[]
	 */
	private static function item_transcript_urls( array $item ): array {
		$urls = [];
		foreach ( (array) ( $item['transcripts'] ?? [] ) as $transcript ) {
			$url = esc_url_raw( (string) ( $transcript['url'] ?? '' ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Set (or with null remove) one entry of the import hashes.
	 *
	 * @param int         $post_id Episode ID.
	 * @param string      $field   Field.
	 * @param string|null $hash    Hash, 'local', or null.
	 * @return void
	 */
	private static function remember( int $post_id, string $field, ?string $hash ): void {
		$hashes = get_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', true );
		$hashes = is_array( $hashes ) ? $hashes : [];
		if ( null === $hash ) {
			unset( $hashes[ $field ] );
		} else {
			$hashes[ $field ] = $hash;
		}
		update_post_meta( $post_id, Episodes::META_PREFIX . 'import_hash', $hashes );
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
		$attachment = self::sideload( $url, $post_id, $title, 'image' );

		return is_wp_error( $attachment ) ? 0 : $attachment;
	}

	/**
	 * Download a remote file into the Media Library in one go (show
	 * artwork; within MediaDownload's time per request). A copy of the same
	 * URL that exists already is reused.
	 *
	 * @param string $url     Remote URL.
	 * @param int    $post_id Parent post.
	 * @param string $title   Attachment title.
	 * @param string $kind    audio|image|transcript.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function sideload( string $url, int $post_id, string $title, string $kind ) {
		$copy = MediaCopy::copy(
			$url,
			$kind,
			$post_id,
			$title,
			[ 'deadline' => microtime( true ) + MediaDownload::request_seconds() ]
		);

		if ( 'copied' === $copy['result'] ) {
			return (int) $copy['attachment'];
		}

		if ( 'failed' !== $copy['result'] ) {
			MediaDownload::discard( (array) $copy['download'] );
		}

		return new \WP_Error( 'epm_media_' . ( '' !== $copy['reason'] ? $copy['reason'] : 'pending' ), '' !== $copy['message'] ? (string) $copy['message'] : __( 'The file did not arrive in time.', 'elementor-podcast-manager' ) );
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

		$this->keep_transcript_file( $post_id, $transcripts );

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
	 * feed can list it for captions: linked where it is, and remembered as
	 * the import's (a move copies a WebVTT/SRT file into the Media Library,
	 * see copy_files()).
	 *
	 * @param int                                           $post_id     Episode ID.
	 * @param array<int, array{url: string, type: string}> $transcripts Normalized transcripts.
	 * @return void
	 */
	private function keep_transcript_file( int $post_id, array $transcripts ): void {
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

		update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', $best['url'] );
		update_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_type', $best['type'] );
		self::remember( $post_id, 'transcript_url', self::hash( (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'transcript_url', true ), 'transcript_url' ) );
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
