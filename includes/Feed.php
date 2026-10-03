<?php
/**
 * Podcast RSS feed (Layer 1). No Elementor dependency.
 *
 * Endpoint: /podcast/feed/ (pretty permalinks) or /?epm_podcast_feed=1.
 * RSS 2.0 + iTunes + Podcasting 2.0 namespaces, suitable for submission to
 * Apple Podcasts, Spotify and other directories.
 *
 * Also serves two per-episode companion documents referenced from the
 * feed: Podcasting 2.0 JSON chapters (?epm_chapters={id}) and an HTML
 * transcript (?epm_transcript={id}). Both only expose publicly-visible
 * episodes.
 *
 * The feed is built a page of episodes at a time (memory does not grow
 * with the catalog) and cached in pieces (FeedStore), invalidated
 * whenever an episode, its media or the podcast settings change.
 * Responses carry ETag and Last-Modified headers and answer conditional
 * requests with 304 from a few hundred bytes of cache, so podcast apps
 * polling the feed stay cheap.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Feed {

	/**
	 * Query var used to detect the feed request.
	 */
	public const QUERY_VAR = 'epm_podcast_feed';

	/**
	 * Query var for the per-episode JSON chapters document.
	 */
	public const CHAPTERS_VAR = 'epm_chapters';

	/**
	 * Query var for the per-episode transcript document.
	 */
	public const TRANSCRIPT_VAR = 'epm_transcript';

	/**
	 * Query var of the previous feed address (PowerPress, Seriously Simple
	 * Podcasting), which redirects to the feed.
	 */
	public const ALIAS_VAR = 'epm_feed_alias';

	/**
	 * Feed name of the previous address (/feed/podcast/, ?feed=podcast).
	 */
	public const ALIAS_FEED = 'podcast';

	/**
	 * End of the document.
	 */
	private const CLOSE = "</channel>\n</rss>\n";

	/**
	 * <lastBuildDate> in a built document.
	 */
	private const BUILD_DATE = '#<lastBuildDate>[^<]*</lastBuildDate>#';

	/**
	 * Cron hook: notify Podcast Index that the feed changed.
	 */
	public const PING_HOOK = 'epm_podcast_index_ping';

	/**
	 * Filter that turns the Podcast Index notification off (return false).
	 * 1.3.0 also used this name for the cron event, so apply_filters() ran
	 * the notification itself; an event scheduled under it is moved to
	 * PING_HOOK (see adopt_legacy_ping()).
	 */
	public const PING_FILTER = 'epm_ping_podcast_index';

	/**
	 * Option holding the immutable podcast:guid.
	 */
	public const GUID_OPTION = 'epm_podcast_guid';

	/**
	 * Hash of the last feed built and when its content last changed
	 * (Last-Modified and lastBuildDate).
	 */
	public const BUILD_OPTION = 'epm_feed_build';

	/**
	 * The feed address the Distribution screen showed (for submitting it
	 * to directories): a later change of the address is reported.
	 */
	public const ADDRESS_OPTION = 'epm_feed_address';

	/**
	 * Podcasting 2.0 namespace UUID for podcast:guid (UUIDv5 of the feed URL).
	 */
	public const GUID_NAMESPACE = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6';

	/**
	 * Wire the endpoint.
	 *
	 * @return void
	 */
	public function init(): void {
		// Priority 1: the /podcast/feed/ rule must be registered BEFORE the
		// episode post type (init:5). The post type's archive-feed rule
		// "podcast/(feed|rdf|rss|rss2|atom)/?$" would otherwise match first
		// and serve WordPress's generic RSS instead of the podcast feed.
		add_action( 'init', [ self::class, 'register_endpoint' ], 1 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		// Before WordPress answers feed requests itself (send_headers).
		add_filter( 'request', [ self::class, 'route_request' ] );
		// Priority 1: answer before canonical redirects and template loading.
		add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
		add_action( 'wp_head', [ $this, 'discovery_link' ], 3 );

		// Cache invalidation.
		add_action( 'save_post', [ $this, 'maybe_flush_for_post' ], 10, 1 );
		add_action( 'deleted_post', [ $this, 'maybe_flush_for_post' ], 10, 1 );
		add_action( 'edit_attachment', [ self::class, 'flush_cache' ] );
		add_action( 'delete_attachment', [ self::class, 'flush_cache' ] );
		add_action( 'transition_post_status', [ $this, 'maybe_flush_on_transition' ], 10, 3 );
		add_action( 'added_post_meta', [ $this, 'maybe_flush_on_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'maybe_flush_on_meta' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'maybe_flush_on_meta' ], 10, 3 );
		// Tell Podcast Index about new episodes (it supplies Fountain,
		// Podverse and many other apps) instead of waiting for its poll.
		add_action( 'transition_post_status', [ $this, 'maybe_schedule_ping' ], 20, 3 );
		add_action( self::PING_HOOK, [ self::class, 'ping_podcast_index' ] );
		add_action( 'init', [ self::class, 'adopt_legacy_ping' ] );

		// The site title and tagline stand in for an empty podcast title and
		// description; the language for the feed's.
		foreach ( [ PodcastSettings::OPTION, 'permalink_structure', 'home', 'blog_charset', 'blogname', 'blogdescription', 'WPLANG' ] as $option ) {
			add_action( 'update_option_' . $option, [ self::class, 'flush_cache' ] );
			add_action( 'add_option_' . $option, [ self::class, 'flush_cache' ] );
		}
		// The previous feed address: its rewrite rule follows the setting.
		add_action( 'update_option_' . PodcastSettings::OPTION, [ self::class, 'alias_setting_changed' ], 10, 2 );
		add_action(
			'add_option_' . PodcastSettings::OPTION,
			static function ( $option, $value ) {
				self::alias_setting_changed( [], $value );
			},
			10,
			2
		);
	}

	/**
	 * Register the /podcast/feed/ rewrite rule.
	 *
	 * Must run before EpisodePostType::register() so this rule is checked
	 * before the post type's own archive-feed rule.
	 *
	 * @return void
	 */
	public static function register_endpoint(): void {
		add_rewrite_rule(
			'^podcast/feed/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);

		if ( self::alias_enabled() ) {
			self::register_alias();
		}
	}

	/**
	 * Whether the previous feed address of a WordPress podcast plugin
	 * (/feed/podcast/, ?feed=podcast) redirects to this feed.
	 *
	 * @return bool
	 */
	public static function alias_enabled(): bool {
		$settings = get_option( PodcastSettings::OPTION, [] );

		return is_array( $settings ) && ! empty( $settings['feed_alias'] );
	}

	/**
	 * Register the "podcast" feed type, so /feed/podcast/ has a rewrite
	 * rule. Requests for it are redirected in route_request().
	 *
	 * @return void
	 */
	private static function register_alias(): void {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof \WP_Rewrite && ! in_array( self::ALIAS_FEED, (array) $wp_rewrite->feeds, true ) ) {
			$wp_rewrite->feeds[] = self::ALIAS_FEED;
		}
	}

	/**
	 * The previous-address setting changed: rebuild the rewrite rules.
	 *
	 * @param mixed $old   Old settings.
	 * @param mixed $value New settings.
	 * @return void
	 */
	public static function alias_setting_changed( $old, $value ): void {
		$before = is_array( $old ) && ! empty( $old['feed_alias'] );
		$after  = is_array( $value ) && ! empty( $value['feed_alias'] );
		if ( $before === $after ) {
			return;
		}

		global $wp_rewrite;
		if ( $after ) {
			self::register_alias();
		} elseif ( $wp_rewrite instanceof \WP_Rewrite ) {
			$wp_rewrite->feeds = array_values( array_diff( (array) $wp_rewrite->feeds, [ self::ALIAS_FEED ] ) );
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Settings of WordPress podcast plugins whose feed lived at
	 * /feed/podcast/ on this site (the plugins may already be removed).
	 *
	 * @return string[] Plugin names.
	 */
	public static function previous_plugins(): array {
		$found = [];
		if ( false !== get_option( 'powerpress_general', false ) ) {
			$found[] = 'PowerPress';
		}
		if ( false !== get_option( 'ss_podcasting_data_title', false ) || false !== get_option( 'ssp_version', false ) ) {
			$found[] = 'Seriously Simple Podcasting';
		}

		return $found;
	}

	/**
	 * Route feed requests before WordPress handles them.
	 *
	 * - Feeds of the episode archive (/podcast/rss2/, /podcast/feed/atom/,
	 *   ?post_type=podcast_episode&feed=rss2 — WordPress's own discovery
	 *   link under plain permalinks) are the podcast feed. Taking them off
	 *   WordPress's feed handling here keeps WordPress from answering a
	 *   conditional request with its own 304 (validated against the last
	 *   post change, not the podcast feed) before the plugin runs.
	 * - The previous address of a WordPress podcast plugin redirects to
	 *   the feed, when that is turned on.
	 * - Under plain permalinks the pretty address /podcast/feed/ (the one a
	 *   directory may have) still serves the feed.
	 *
	 * @param array<string, mixed> $vars Query vars.
	 * @return array<string, mixed>
	 */
	public static function route_request( $vars ): array {
		$vars = is_array( $vars ) ? $vars : [];
		$feed = isset( $vars['feed'] ) && is_string( $vars['feed'] ) ? $vars['feed'] : '';
		$rest = array_diff( array_keys( $vars ), [ 'feed', 'post_type' ] );

		if ( '' !== $feed && EpisodePostType::CPT === ( $vars['post_type'] ?? '' ) && empty( $rest ) ) {
			unset( $vars['feed'], $vars['post_type'] );
			$vars[ self::QUERY_VAR ] = '1';
			return $vars;
		}

		if ( self::ALIAS_FEED === $feed && ! isset( $vars['post_type'] ) && empty( $rest ) && self::alias_enabled() ) {
			unset( $vars['feed'] );
			$vars[ self::ALIAS_VAR ] = '1';
			return $vars;
		}

		if ( empty( $vars ) && isset( $_SERVER['REQUEST_URI'] ) ) {
			global $wp_rewrite;
			if ( $wp_rewrite instanceof \WP_Rewrite && ! $wp_rewrite->using_permalinks() ) {
				$path = trim( (string) wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
				$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
				if ( ( '' === $home ? '' : $home . '/' ) . 'podcast/feed' === $path ) {
					$vars[ self::QUERY_VAR ] = '1';
				}
			}
		}

		return $vars;
	}

	/**
	 * Add the query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::ALIAS_VAR;
		$vars[] = self::CHAPTERS_VAR;
		$vars[] = self::TRANSCRIPT_VAR;

		return $vars;
	}

	/**
	 * Whether the current request asks for the podcast feed.
	 *
	 * Besides the explicit query var, every feed of the episode archive
	 * (/podcast/feed/, /podcast/rss2/, /podcast/feed/atom/ …) serves the
	 * podcast feed: WordPress's generic archive feed would expose episodes
	 * without enclosures, including password-protected ones.
	 *
	 * @return bool
	 */
	public static function is_feed_request(): bool {
		if ( '1' === (string) get_query_var( self::QUERY_VAR ) ) {
			return true;
		}

		return is_feed() && is_post_type_archive( EpisodePostType::CPT );
	}

	/**
	 * Render the feed or a companion document when requested.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( self::is_feed_request() ) {
			$this->serve_feed();
			exit;
		}

		if ( '1' === (string) get_query_var( self::ALIAS_VAR ) ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the feed may be at the host, by design.
			wp_redirect( Hosting::public_feed_url(), 301, 'Elementor Podcast Manager' );
			exit;
		}

		$chapters = absint( get_query_var( self::CHAPTERS_VAR ) );
		if ( $chapters > 0 ) {
			$this->serve_chapters( $chapters );
			exit;
		}

		$transcript = absint( get_query_var( self::TRANSCRIPT_VAR ) );
		if ( $transcript > 0 ) {
			$this->serve_transcript( $transcript );
			exit;
		}
	}

	/**
	 * Get the public feed URL.
	 * Works with pretty and plain permalink configurations.
	 *
	 * @return string
	 */
	public static function url(): string {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ) {
			return home_url( '/podcast/feed/' );
		}

		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Remember the feed address shown for submitting it to directories
	 * (the first time it is shown).
	 *
	 * @return void
	 */
	public static function remember_address(): void {
		if ( ! Hosting::is_external() && '' === (string) get_option( self::ADDRESS_OPTION, '' ) ) {
			update_option( self::ADDRESS_OPTION, self::url(), false );
		}
	}

	/**
	 * The new address becomes the one directories know (after the user
	 * submitted it again, or set up a redirect).
	 *
	 * @return void
	 */
	public static function accept_address(): void {
		update_option( self::ADDRESS_OPTION, self::url(), false );
	}

	/**
	 * When this site publishes the feed and its address differs from the
	 * one shown for submitting it (the permalink setting changed, the site
	 * moved), both addresses; null otherwise. Scheme and trailing slash do
	 * not count.
	 *
	 * @return array{shown: string, now: string}|null
	 */
	public static function address_change(): ?array {
		$shown = (string) get_option( self::ADDRESS_OPTION, '' );
		if ( '' === $shown || Hosting::is_external() ) {
			return null;
		}

		$now       = self::url();
		$normalize = static function ( string $url ): string {
			return strtolower( untrailingslashit( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $url ) ) );
		};

		return $normalize( $shown ) === $normalize( $now ) ? null : [
			'shown' => $shown,
			'now'   => $now,
		];
	}

	/**
	 * URL of an episode's Podcasting 2.0 JSON chapters document.
	 *
	 * @param int $episode_id Episode ID.
	 * @return string
	 */
	public static function chapters_url( int $episode_id ): string {
		return add_query_arg( self::CHAPTERS_VAR, $episode_id, home_url( '/' ) );
	}

	/**
	 * URL of an episode's transcript document.
	 *
	 * @param int $episode_id Episode ID.
	 * @return string
	 */
	public static function transcript_url( int $episode_id ): string {
		return add_query_arg( self::TRANSCRIPT_VAR, $episode_id, home_url( '/' ) );
	}

	/**
	 * Print a feed discovery <link> so apps and directories can find the feed.
	 *
	 * The episode archive already gets one from WordPress core
	 * (feed_links_extra), which now resolves to the podcast feed.
	 *
	 * @return void
	 */
	public function discovery_link(): void {
		if ( ! epm()->settings->is_configured() || is_post_type_archive( EpisodePostType::CPT ) ) {
			return;
		}

		printf(
			'<link rel="alternate" type="application/rss+xml" title="%s" href="%s" />' . "\n",
			esc_attr( (string) epm()->settings->get( 'title' ) ),
			esc_url( Hosting::public_feed_url() )
		);
	}

	/**
	 * Escape a URL for XML output.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function xml_url( string $url ): string {
		return epm_esc_xml( esc_url_raw( $url ) );
	}

	/**
	 * Turn possibly HTML-encoded display text (e.g. get_the_title() output
	 * with &#8220; entities from wptexturize) into plain text for XML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain_text( string $text ): string {
		// Invalid UTF-8 would make the whitespace pattern below fail and
		// empty the text.
		$text = wp_strip_all_tags( epm_xml_chars( $text ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Wrap HTML in a CDATA section (splitting any "]]>" it contains).
	 * Characters XML 1.0 does not allow even in CDATA (control characters,
	 * U+FFFE/U+FFFF, invalid UTF-8) are removed (epm_xml_chars()).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function cdata( string $html ): string {
		$html = epm_xml_chars( $html );

		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $html ) . ']]>';
	}

	/**
	 * Normalize a WordPress locale to a valid RSS language tag.
	 * e.g. de_DE -> de-de, en_US -> en-us. Falls back to "en".
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function rss_language( string $locale ): string {
		$tag = strtolower( str_replace( '_', '-', trim( $locale ) ) );

		if ( preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $tag ) ) {
			return $tag;
		}

		// Locales with variants (e.g. de_DE_formal): keep language + region.
		if ( preg_match( '/^([a-z]{2,3}-[a-z]{2,4})-/', $tag, $m ) ) {
			return $m[1];
		}

		return 'en';
	}

	/**
	 * Generate a name-based UUID (version 5).
	 *
	 * @param string $namespace Namespace UUID.
	 * @param string $name      Name.
	 * @return string
	 */
	public static function uuid_v5( string $namespace, string $name ): string {
		$ns_bytes = (string) hex2bin( str_replace( '-', '', $namespace ) );
		$hash     = sha1( $ns_bytes . $name );

		return sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * The podcast's immutable podcast:guid.
	 *
	 * Derived once from the feed URL (scheme and trailing slashes removed,
	 * as the Podcasting 2.0 spec requires), then stored so a later domain or
	 * permalink change never changes the podcast's identity.
	 *
	 * @return string
	 */
	public static function podcast_guid(): string {
		$guid = (string) get_option( self::GUID_OPTION, '' );

		if ( '' === $guid ) {
			$guid = self::derived_podcast_guid();
			update_option( self::GUID_OPTION, $guid, false );
		}

		return $guid;
	}

	/**
	 * The podcast:guid this site derives from its own feed address (what
	 * podcast_guid() stores when nothing else set one).
	 *
	 * @return string
	 */
	public static function derived_podcast_guid(): string {
		return self::uuid_v5( self::GUID_NAMESPACE, rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', self::url() ), '/' ) );
	}

	/**
	 * Get the episodes eligible for the feed, honoring the feed-window policy.
	 *
	 * Policy (documented in README + settings UI): the feed contains the
	 * newest N episodes with distribution-ready audio, where N is the
	 * "Feed episode limit" setting (0 = unlimited). Episodes without audio
	 * or with non-distribution audio (e.g. WAV) never displace eligible
	 * episodes — filtering happens before the window is applied.
	 * Serial podcasts are ordered oldest-first; episodic newest-first. The
	 * window always keeps the newest episodes, for serial shows too.
	 *
	 * Kept for integrations: the feed itself is built a page at a time
	 * (walk()) and never holds every episode.
	 *
	 * @return array<int, \WP_Post>
	 */
	public function eligible_episodes(): array {
		$posts = [];
		$this->walk(
			static function ( \WP_Post $post ) use ( &$posts ) {
				$posts[] = $post;
			}
		);

		return $posts;
	}

	/**
	 * IDs of the newest episodes with distribution-ready audio, newest
	 * first (the feed window before serial ordering).
	 *
	 * @param int $limit At most this many (0 = all).
	 * @return int[]
	 */
	public function eligible_ids( int $limit ): array {
		$ids = [];
		$this->scan(
			'DESC',
			$limit,
			static function ( \WP_Post $post ) use ( &$ids ) {
				$ids[] = $post->ID;
			}
		);

		return $ids;
	}

	/**
	 * Visit the feed's episodes in feed order, a page at a time: each
	 * page's posts, meta and attachments are loaded, visited and released
	 * before the next one, so memory does not grow with the catalog.
	 *
	 * Episodic: newest first, stopping at the limit. Serial: oldest first;
	 * with a limit the newest N are found first (newest first) and then
	 * visited in reverse.
	 *
	 * @param callable $visit Receives ( \WP_Post $post, array $data ) of each episode.
	 * @return void
	 */
	private function walk( callable $visit ): void {
		$settings = epm()->settings;
		$limit    = max( 0, (int) $settings->get( 'feed_limit' ) );
		$serial   = 'serial' === (string) $settings->get( 'type' );

		if ( ! $serial ) {
			$this->scan( 'DESC', $limit, $visit );
		} elseif ( 0 === $limit ) {
			$this->scan( 'ASC', 0, $visit );
		} else {
			foreach ( array_chunk( array_reverse( $this->eligible_ids( $limit ) ), Episodes::PAGE ) as $page ) {
				$this->visit_page( $page, $visit );
			}
		}
	}

	/**
	 * Visit eligible episodes by date (then ID) in pages of candidates
	 * fetched with SQL, until $limit were visited.
	 *
	 * @param string   $order DESC (newest first) or ASC.
	 * @param int      $limit Stop after this many (0 = all).
	 * @param callable $visit Receives ( \WP_Post $post, array $data ).
	 * @return void
	 */
	private function scan( string $order, int $limit, callable $visit ): void {
		$after = null;
		$found = 0;

		do {
			// A little more than the window still needs: episodes without
			// distributable audio are skipped.
			$want = $limit > 0 ? min( Episodes::PAGE, max( 20, (int) ceil( ( $limit - $found ) * 1.25 ) ) ) : Episodes::PAGE;
			$rows = Episodes::public_page( $order, $after, $want );
			if ( empty( $rows ) ) {
				return;
			}
			$last  = end( $rows );
			$after = [ (string) $last[1], (int) $last[0] ];

			$this->visit_page(
				self::with_audio( array_map( 'intval', array_column( $rows, 0 ) ) ),
				static function ( \WP_Post $post, array $data ) use ( $visit, $limit, &$found ) {
					if ( ! AudioMetadata::enclosure( $data )['distributable'] ) {
						return true;
					}
					$visit( $post, $data );
					++$found;

					return 0 === $limit || $found < $limit;
				}
			);
		} while ( ( 0 === $limit || $found < $limit ) && count( $rows ) === $want );
	}

	/**
	 * Load a page of episodes (in batches that fit the memory budget, see
	 * Episodes::batches()), pass the publicly visible ones to $fn, then
	 * release them.
	 *
	 * @param int[]    $ids Episode IDs.
	 * @param callable $fn  Receives ( \WP_Post $post, array $data ); returning false stops.
	 * @return void
	 */
	private function visit_page( array $ids, callable $fn ): void {
		foreach ( Episodes::batches( $ids ) as $batch ) {
			$attachments = Episodes::prime_page( $batch );
			$go_on       = true;

			foreach ( $batch as $id ) {
				$post = get_post( $id );
				$data = $post ? epm()->episodes->get_public_data( $post ) : null;
				if ( ! $data ) {
					continue;
				}
				if ( false === $fn( $post, $data ) ) {
					$go_on = false;
					break;
				}
			}

			Episodes::release_caches( array_merge( $batch, $attachments ) );
			if ( ! $go_on ) {
				return;
			}
		}
	}

	/**
	 * The episodes among $ids that have audio: a Media Library file
	 * (_epm_audio_id > 0) or an audio URL (_epm_audio_url containing
	 * "://"), the same condition as Episodes::audio_meta_query().
	 *
	 * @param int[] $ids Episode IDs, in order.
	 * @return int[] In the same order.
	 */
	private static function with_audio( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return [];
		}

		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND meta_key IN (%s, %s)', Episodes::META_PREFIX . 'audio_id', Episodes::META_PREFIX . 'audio_url' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		$audio = [];
		foreach ( $rows as $row ) {
			$value = (string) $row->meta_value;
			if ( Episodes::META_PREFIX . 'audio_id' === $row->meta_key ? (int) $value > 0 : false !== strpos( $value, '://' ) ) {
				$audio[ (int) $row->post_id ] = true;
			}
		}

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $audio ) {
					return isset( $audio[ $id ] );
				}
			)
		);
	}

	/**
	 * Schedule a Podcast Index notification when an episode goes live.
	 *
	 * Only for feeds this site publishes, on public sites (Settings →
	 * Reading → search engine visibility), and deferred one minute so a
	 * burst of publishes sends one request. Filter epm_ping_podcast_index
	 * to turn it off.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function maybe_schedule_ping( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || EpisodePostType::CPT !== $post->post_type ) {
			return;
		}

		if ( Hosting::is_external() || ! get_option( 'blog_public' ) || ! (bool) apply_filters( self::PING_FILTER, true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::PING_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PING_HOOK );
		}
	}

	/**
	 * Move a notification 1.3.0 scheduled under the filter's name to the
	 * ping event, keeping its time. Without this the old event would run
	 * the opt-out filter's callbacks instead of the notification.
	 *
	 * @return void
	 */
	public static function adopt_legacy_ping(): void {
		$when = wp_next_scheduled( self::PING_FILTER );
		if ( false === $when ) {
			return;
		}

		wp_clear_scheduled_hook( self::PING_FILTER );
		if ( ! wp_next_scheduled( self::PING_HOOK ) ) {
			wp_schedule_single_event( (int) $when, self::PING_HOOK );
		}
	}

	/**
	 * Notify Podcast Index (hub/pubnotify needs no API key).
	 *
	 * @return void
	 */
	public static function ping_podcast_index(): void {
		// Eligibility can change while the deferred notification waits for cron.
		if ( Hosting::is_external() || ! get_option( 'blog_public' ) || ! (bool) apply_filters( self::PING_FILTER, true ) ) {
			return;
		}

		wp_safe_remote_get(
			'https://api.podcastindex.org/api/1.0/hub/pubnotify?url=' . rawurlencode( self::url() ),
			[
				'timeout'    => 10,
				'blocking'   => false,
				'user-agent' => 'ElementorPodcastManager/' . EPM_VERSION . '; ' . home_url( '/' ),
			]
		);
	}

	/**
	 * Flush the cached feed.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		FeedStore::flush();
	}

	/**
	 * A build time in the future (a post dated ahead, a server clock
	 * that was ahead) would answer every If-Modified-Since with 304 until
	 * that date: bring a stored one back to now (after an update).
	 *
	 * @return void
	 */
	public static function repair_build_time(): void {
		$built = get_option( self::BUILD_OPTION, [] );
		if ( is_array( $built ) && (int) ( $built['modified'] ?? 0 ) > time() ) {
			$built['modified'] = time();
			update_option( self::BUILD_OPTION, $built, false );
		}
	}

	/**
	 * Flush the cache when an episode or attachment is saved or deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function maybe_flush_for_post( $post_id ): void {
		if ( ! empty( $GLOBALS['epm_import_step_active'] ) ) {
			$GLOBALS['epm_import_step_dirty'] = true;
			return;
		}
		$type = get_post_type( (int) $post_id );

		if ( EpisodePostType::CPT === $type || 'attachment' === $type ) {
			self::flush_cache();
		}
	}

	/**
	 * Flush the cache on episode status changes (publish, unpublish, a
	 * scheduled episode going live via cron, trash).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function maybe_flush_on_transition( $new_status, $old_status, $post ): void {
		if ( ! empty( $GLOBALS['epm_import_step_active'] ) ) {
			$GLOBALS['epm_import_step_dirty'] = true;
			return;
		}
		if ( $post instanceof \WP_Post && EpisodePostType::CPT === $post->post_type ) {
			self::flush_cache();
		}
	}

	/**
	 * Flush the cache when episode meta changes outside the editor (REST, CLI),
	 * or an attachment's file or metadata changes. Only feed-relevant keys
	 * count, so editor heartbeats (_edit_lock) never invalidate the cache.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function maybe_flush_on_meta( $meta_id, $object_id, $meta_key = '' ): void {
		if ( ! empty( $GLOBALS['epm_import_step_active'] ) ) {
			$GLOBALS['epm_import_step_dirty'] = true;
			return;
		}
		$meta_key = (string) $meta_key;

		// A media file replaced in place (a new file, new length or
		// duration): its URL and size are in the feed.
		if ( in_array( $meta_key, [ '_wp_attached_file', '_wp_attachment_metadata' ], true ) ) {
			if ( 'attachment' === get_post_type( (int) $object_id ) ) {
				self::flush_cache();
			}
			return;
		}

		if ( 0 !== strpos( $meta_key, Episodes::META_PREFIX ) && '_thumbnail_id' !== $meta_key ) {
			return;
		}

		// Derived from the duration, not in the feed: the upgrade writes it
		// for every episode.
		if ( Episodes::META_PREFIX . 'duration_seconds' === $meta_key ) {
			return;
		}

		if ( EpisodePostType::CPT === get_post_type( (int) $object_id ) ) {
			self::flush_cache();
		}
	}

	/**
	 * Get the rendered feed, from cache when possible.
	 *
	 * Returns the whole document as one string (for integrations and
	 * tests); serve_feed() sends a stored feed piece by piece instead.
	 *
	 * @return array{xml: string, modified: int, etag: string}
	 */
	public function get_document(): array {
		$document = $this->document();
		$xml      = $document['xml'];

		if ( null === $xml ) {
			$xml      = '';
			$complete = FeedStore::each(
				$document,
				static function ( string $piece ) use ( &$xml ) {
					$xml .= $piece;
				}
			);
			if ( ! $complete ) {
				// Removed while it was read: build it for this call.
				$document = $this->build_document( '' );
				$xml      = (string) $document['xml'];
			}
		}
		$this->done_with( $document );

		return [
			'xml'      => $xml,
			'modified' => (int) $document['modified'],
			'etag'     => (string) $document['etag'],
		];
	}

	/**
	 * The first item of the feed as directories read it: title and
	 * enclosure (address with the download-statistics prefix, length and
	 * type). Null when the feed has no item.
	 *
	 * @return array{title: string, url: string, length: int, type: string}|null
	 */
	public function first_item(): ?array {
		$document = $this->document();
		$text     = (string) $document['xml'];
		if ( null === $document['xml'] ) {
			FeedStore::each(
				$document,
				static function ( string $piece ) use ( &$text ) {
					// The channel part and the first piece of items suffice.
					$text .= $piece;
					return false === strpos( $text, '</item>' );
				}
			);
		}
		$this->done_with( $document );

		if ( ! preg_match( '#<item>(.*?)</item>#s', $text, $item ) || ! preg_match( '#<enclosure url="([^"]*)" length="(\d*)" type="([^"]*)"#', $item[1], $enclosure ) ) {
			return null;
		}
		$title = preg_match( '#<title>(.*?)</title>#s', $item[1], $m ) ? $m[1] : '';

		return [
			'title'  => html_entity_decode( $title, ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			'url'    => html_entity_decode( $enclosure[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			'length' => (int) $enclosure[2],
			'type'   => html_entity_decode( $enclosure[3], ENT_QUOTES | ENT_XML1, 'UTF-8' ),
		];
	}

	/**
	 * The feed document: the stored one, or a new build (stored when no
	 * change of the show overtook it).
	 *
	 * Only one request builds at a time: others wait for its result for up
	 * to ten seconds, then build for themselves without storing.
	 *
	 * @return array{gen: string, chunks: int, etag: string, modified: int, bytes: int, xml: string|null, stored: bool}
	 */
	private function document(): array {
		if ( ! (bool) apply_filters( 'epm_feed_cache_enabled', true ) ) {
			return $this->build_document( '' );
		}

		$wait = microtime( true ) + 10;
		do {
			$stored = FeedStore::current();
			if ( null !== $stored ) {
				return $stored + [
					'xml'    => null,
					'stored' => true,
				];
			}

			$claim = FeedStore::claim();
			if ( null !== $claim ) {
				try {
					$document = $this->build_document( $claim['gen'] );
				} catch ( \Throwable $e ) {
					FeedStore::release( $claim );
					FeedStore::discard( $claim['gen'] );
					throw $e;
				}
				if ( null !== $document['xml'] ) {
					// The database refused a piece: served from memory.
					FeedStore::release( $claim );
					return $document;
				}
				$document['stored'] = FeedStore::publish( $claim, $document );

				return $document;
			}

			// Another request is building it.
			usleep( 100000 );
		} while ( microtime( true ) < $wait );

		return $this->build_document( FeedStore::new_gen() );
	}

	/**
	 * Remove the pieces of a build nobody else will read (one that was not
	 * stored).
	 *
	 * @param array<string, mixed> $document Document.
	 * @return void
	 */
	private function done_with( array $document ): void {
		if ( empty( $document['stored'] ) && '' !== (string) ( $document['gen'] ?? '' ) ) {
			FeedStore::discard( (string) $document['gen'] );
		}
	}

	/**
	 * Build the feed a page of episodes at a time. The items go to the
	 * feed cache as they are built (in pieces named after $gen), or stay in
	 * memory when $gen is ''.
	 *
	 * Last-Modified and <lastBuildDate> follow the content: channel
	 * settings, a removed episode or a lower episode limit change the feed
	 * without a newer episode date, so the build time moves only when the
	 * content changed since the last build (remembered in BUILD_OPTION,
	 * which outlives the cache). It is never later than now: an episode
	 * dated in the future would otherwise freeze Last-Modified there.
	 *
	 * @param string $gen Build name ('' to build in memory).
	 * @return array{gen: string, chunks: int, etag: string, modified: int, bytes: int, xml: string|null, stored: bool}
	 */
	private function build_document( string $gen ): array {
		$writer = new FeedWriter( $gen );
		$now    = time();
		$state  = [
			'newest'   => 0,
			'modified' => 0,
			'count'    => 0,
			'trailers' => [],
		];

		$this->walk(
			function ( \WP_Post $post, array $data ) use ( $writer, $now, &$state ) {
				$trailer = $this->trailer( $data );
				if ( '' !== $trailer ) {
					$state['trailers'][] = $trailer;
				}

				$published         = (int) get_post_time( 'U', true, $post );
				$state['newest']   = max( $state['newest'], $published );
				$state['modified'] = max( $state['modified'], min( $now, max( (int) get_post_modified_time( 'U', true, $post ), $published ) ) );
				++$state['count'];

				$item = $this->build_item( $post );
				if ( '' !== $item ) {
					$writer->write( $item . "\n" );
				}
			}
		);
		$writer->write( self::CLOSE );

		$modified = $state['modified'] > 0 ? $state['modified'] : $now;
		$head     = $this->channel( $modified, $state['newest'], $state['count'] > 0, $state['trailers'] );
		$items    = $writer->items_hash();
		$hash     = md5( (string) preg_replace( self::BUILD_DATE, '', $head ) . $items );

		$built        = get_option( self::BUILD_OPTION, [] );
		$built        = is_array( $built ) ? $built : [];
		$built_time   = (int) ( $built['modified'] ?? 0 );
		$same_content = ( $built['hash'] ?? '' ) === $hash;
		$modified     = $same_content ? min( $now, max( $modified, $built_time ) ) : $now;
		if ( ! $same_content || $built_time > $now ) {
			update_option(
				self::BUILD_OPTION,
				[
					'hash'     => $hash,
					'modified' => $modified,
				],
				false
			);
		}

		$head   = (string) preg_replace( self::BUILD_DATE, '<lastBuildDate>' . gmdate( 'D, d M Y H:i:s', $modified ) . ' +0000</lastBuildDate>', $head, 1 );
		$result = $writer->finish( $head );

		return [
			'gen'      => $writer->gen(),
			'chunks'   => $result['chunks'],
			'etag'     => '"' . md5( $head . $items ) . '"',
			'modified' => $modified,
			'bytes'    => $result['bytes'],
			'xml'      => $result['xml'],
			'stored'   => false,
		];
	}

	/**
	 * Whether an If-None-Match header matches the feed's ETag: '*', or one
	 * of the listed entity tags (weak or strong, compared whole).
	 *
	 * @param string $header If-None-Match header.
	 * @param string $etag   The feed's ETag (quoted).
	 * @return bool
	 */
	public static function etag_matches( string $header, string $etag ): bool {
		$header = trim( $header );
		if ( '*' === $header ) {
			return true;
		}

		$want = trim( $etag, '"' );
		foreach ( explode( ',', $header ) as $tag ) {
			$tag = trim( $tag );
			if ( 0 === strncasecmp( $tag, 'W/', 2 ) ) {
				$tag = substr( $tag, 2 );
			}
			// Some clients drop the quotes.
			if ( '' !== $want && trim( $tag, '"' ) === $want ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send the feed with conditional-GET support.
	 *
	 * @return void
	 */
	public function serve_feed(): void {
		// Another host publishes the show: send apps and directories there
		// permanently (301), the standard way to move a podcast feed.
		$redirect = Hosting::feed_redirect_target();
		if ( '' !== $redirect ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the host's feed is external by design; admins set it.
			wp_redirect( $redirect, 301, 'Elementor Podcast Manager' );
			return;
		}

		$document = $this->document();

		$if_none_match     = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? wp_unslash( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.

		$not_modified = false;
		if ( '' !== $if_none_match ) {
			$not_modified = self::etag_matches( $if_none_match, $document['etag'] );
		} elseif ( '' !== $if_modified_since ) {
			$since        = strtotime( $if_modified_since );
			$not_modified = false !== $since && $since >= $document['modified'];
		}

		// The pieces of a stored feed can be gone (removed, or swept after
		// a later build): build it again before anything is sent.
		if ( ! $not_modified && null === $document['xml'] && ! FeedStore::complete( $document ) ) {
			FeedStore::flush();
			$document = $this->document();
		}

		$charset = (string) get_option( 'blog_charset' );

		status_header( 200 );
		header( 'Content-Type: application/rss+xml; charset=' . ( '' !== $charset ? $charset : 'UTF-8' ), true );
		header( 'ETag: ' . $document['etag'] );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $document['modified'] ) . ' GMT' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( $not_modified ) {
			status_header( 304 );
			$this->done_with( $document );
			return;
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
			$this->done_with( $document );
			return;
		}

		if ( null !== $document['xml'] ) {
			echo $document['xml']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		} else {
			FeedStore::each(
				$document,
				static function ( string $piece ) {
					echo $piece; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				}
			);
		}
		$this->done_with( $document );
	}

	/**
	 * Kept for backwards compatibility: print the feed.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->serve_feed();
	}

	/**
	 * Build the RSS document for a list of episodes (kept for
	 * integrations; the feed itself is built by build_document()).
	 *
	 * @param array<int, \WP_Post> $episodes Eligible episodes, in feed order.
	 * @param int                  $modified Last-build timestamp.
	 * @return string
	 */
	public function build( array $episodes, int $modified ): string {
		$trailers = [];
		$newest   = 0;
		foreach ( $episodes as $post ) {
			$newest = max( $newest, (int) get_post_time( 'U', true, $post ) );
			$data   = epm()->episodes->get_public_data( $post );
			if ( $data ) {
				$trailer = $this->trailer( $data );
				if ( '' !== $trailer ) {
					$trailers[] = $trailer;
				}
			}
		}

		$items = '';
		foreach ( $episodes as $post ) {
			$item = $this->build_item( $post );
			if ( '' !== $item ) {
				$items .= $item . "\n";
			}
		}

		return $this->channel( $modified, $newest, ! empty( $episodes ), $trailers ) . $items . self::CLOSE;
	}

	/**
	 * The channel part of the document: everything before the first item.
	 *
	 * @param int      $modified     Last-build timestamp.
	 * @param int      $newest       Publish time of the newest episode in the feed.
	 * @param bool     $has_episodes Whether the feed has episodes.
	 * @param string[] $trailers     <podcast:trailer> lines, in feed order.
	 * @return string
	 */
	private function channel( int $modified, int $newest, bool $has_episodes, array $trailers ): string {
		$settings = epm()->settings;
		$charset  = (string) get_option( 'blog_charset' );
		$charset  = '' !== $charset ? $charset : 'UTF-8';

		$title = self::plain_text( (string) $settings->get( 'title' ) );
		if ( '' === $title ) {
			$title = self::plain_text( (string) get_bloginfo( 'name' ) );
		}

		$link = (string) $settings->get( 'website_url' );
		if ( '' === $link ) {
			$link = home_url( '/' );
		}

		$description = self::plain_text( (string) $settings->get( 'description' ) );
		if ( '' === $description ) {
			$description = self::plain_text( (string) $settings->get( 'short_description' ) );
		}
		if ( '' === $description ) {
			$description = self::plain_text( (string) get_bloginfo( 'description' ) );
		}

		$x   = [];
		$x[] = '<?xml version="1.0" encoding="' . epm_esc_xml( $charset ) . '"?>';
		$x[] = '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">';
		$x[] = '<channel>';
		$x[] = "\t<title>" . epm_esc_xml( $title ) . '</title>';
		$x[] = "\t<link>" . self::xml_url( $link ) . '</link>';
		$x[] = "\t" . '<atom:link href="' . self::xml_url( self::url() ) . '" rel="self" type="application/rss+xml" />';
		$x[] = "\t<description>" . epm_esc_xml( $description ) . '</description>';
		$x[] = "\t<language>" . epm_esc_xml( self::rss_language( (string) $settings->get( 'language' ) ) ) . '</language>';

		$copyright = self::plain_text( (string) $settings->get( 'copyright' ) );
		if ( '' !== $copyright ) {
			$x[] = "\t<copyright>" . epm_esc_xml( $copyright ) . '</copyright>';
		}

		$x[] = "\t<lastBuildDate>" . gmdate( 'D, d M Y H:i:s', $modified ) . ' +0000</lastBuildDate>';
		if ( $has_episodes ) {
			$x[] = "\t<pubDate>" . gmdate( 'D, d M Y H:i:s', $newest ) . ' +0000</pubDate>';
		}
		$x[] = "\t<generator>" . epm_esc_xml( 'Elementor Podcast Manager ' . EPM_VERSION ) . '</generator>';

		$artwork = $settings->artwork_url( 'full' );
		if ( '' !== $artwork ) {
			$x[] = "\t<image>";
			$x[] = "\t\t<url>" . self::xml_url( $artwork ) . '</url>';
			$x[] = "\t\t<title>" . epm_esc_xml( $title ) . '</title>';
			$x[] = "\t\t<link>" . self::xml_url( $link ) . '</link>';
			$x[] = "\t</image>";
			$x[] = "\t" . '<itunes:image href="' . self::xml_url( $artwork ) . '" />';
		}

		$x[] = "\t<itunes:summary>" . epm_esc_xml( $description ) . '</itunes:summary>';
		$x[] = "\t<itunes:author>" . epm_esc_xml( self::plain_text( $settings->effective_author() ) ) . '</itunes:author>';

		$owner_name  = self::plain_text( (string) $settings->get( 'owner_name' ) );
		$owner_email = (string) $settings->get( 'owner_email' );
		if ( '' !== $owner_name || '' !== $owner_email ) {
			$x[] = "\t<itunes:owner>";
			if ( '' !== $owner_name ) {
				$x[] = "\t\t<itunes:name>" . epm_esc_xml( $owner_name ) . '</itunes:name>';
			}
			if ( '' !== $owner_email ) {
				$x[] = "\t\t<itunes:email>" . epm_esc_xml( $owner_email ) . '</itunes:email>';
			}
			$x[] = "\t</itunes:owner>";
		}

		$category    = (string) $settings->get( 'category' );
		$subcategory = (string) $settings->get( 'subcategory' );
		if ( '' !== $category ) {
			if ( '' !== $subcategory ) {
				$x[] = "\t" . '<itunes:category text="' . epm_esc_xml( $category ) . '">';
				$x[] = "\t\t" . '<itunes:category text="' . epm_esc_xml( $subcategory ) . '" />';
				$x[] = "\t</itunes:category>";
			} else {
				$x[] = "\t" . '<itunes:category text="' . epm_esc_xml( $category ) . '" />';
			}
		}

		$x[] = "\t<itunes:explicit>" . ( 'explicit' === $settings->get( 'explicit' ) ? 'true' : 'false' ) . '</itunes:explicit>';
		$x[] = "\t<itunes:type>" . epm_esc_xml( (string) $settings->get( 'type' ) ) . '</itunes:type>';

		// Moving away: the new address. Moved here: this feed's own address,
		// as Apple asks of the new feed after a host change.
		$new_feed_url = (string) $settings->get( 'new_feed_url' );
		if ( '' === $new_feed_url && ! empty( $settings->get( 'moved_in' ) ) ) {
			$new_feed_url = self::url();
		}
		if ( '' !== $new_feed_url ) {
			$x[] = "\t<itunes:new-feed-url>" . self::xml_url( $new_feed_url ) . '</itunes:new-feed-url>';
		}
		if ( ! empty( $settings->get( 'itunes_block' ) ) ) {
			$x[] = "\t<itunes:block>Yes</itunes:block>";
		}
		if ( ! empty( $settings->get( 'complete' ) ) ) {
			$x[] = "\t<itunes:complete>Yes</itunes:complete>";
		}

		$locked = ! empty( $settings->get( 'locked' ) ) ? 'yes' : 'no';
		if ( '' !== $owner_email ) {
			$x[] = "\t" . '<podcast:locked owner="' . epm_esc_xml( $owner_email ) . '">' . $locked . '</podcast:locked>';
		} else {
			$x[] = "\t<podcast:locked>" . $locked . '</podcast:locked>';
		}
		$x[] = "\t<podcast:guid>" . epm_esc_xml( self::podcast_guid() ) . '</podcast:guid>';
		$x[] = "\t<podcast:medium>podcast</podcast:medium>";

		$host = self::plain_text( (string) $settings->get( 'host' ) );
		if ( '' !== $host ) {
			$x[] = "\t" . '<podcast:person role="host">' . epm_esc_xml( $host ) . '</podcast:person>';
		}

		$funding_url = (string) $settings->get( 'funding_url' );
		if ( '' !== $funding_url ) {
			$funding_label = self::plain_text( (string) $settings->get( 'funding_label' ) );
			if ( '' === $funding_label ) {
				$funding_label = __( 'Support the show', 'elementor-podcast-manager' );
			}
			$x[] = "\t" . '<podcast:funding url="' . self::xml_url( $funding_url ) . '">' . epm_esc_xml( $funding_label ) . '</podcast:funding>';
		}

		// Podcasting 2.0: trailers are announced at channel level too, so
		// apps can offer them before the first full episode.
		foreach ( $trailers as $trailer ) {
			$x[] = $trailer;
		}

		return implode( "\n", $x ) . "\n";
	}

	/**
	 * The <podcast:trailer> line for an episode, '' when it is no trailer.
	 *
	 * @param array<string, mixed> $data Episode data.
	 * @return string
	 */
	private function trailer( array $data ): string {
		if ( 'trailer' !== $data['episode_type'] ) {
			return '';
		}
		$enclosure = AudioMetadata::enclosure( $data );
		if ( '' === $enclosure['url'] || ! $enclosure['distributable'] ) {
			return '';
		}
		$season = (int) $data['season_number'];

		return "\t" . '<podcast:trailer pubdate="' . epm_esc_xml( (string) $data['date_rfc2822'] ) . '" url="' . self::xml_url( Hosting::measured_url( $enclosure['url'] ) ) . '"'
			. ( $enclosure['length'] > 0 ? ' length="' . (int) $enclosure['length'] . '"' : '' )
			. ' type="' . epm_esc_xml( $enclosure['type'] ) . '"'
			. ( $season > 0 ? ' season="' . $season . '"' : '' )
			. '>' . epm_esc_xml( self::plain_text( (string) $data['title'] ) ) . '</podcast:trailer>';
	}

	/**
	 * Build one episode item.
	 *
	 * @param \WP_Post $post Episode post.
	 * @return string Empty when the episode must not be distributed.
	 */
	private function build_item( \WP_Post $post ): string {
		// Defense in depth: only publicly-visible episodes reach the feed.
		$data = epm()->episodes->get_public_data( $post );

		if ( ! $data ) {
			return '';
		}

		$data = apply_filters( 'epm_feed_episode', $data, $post );

		$enclosure = AudioMetadata::enclosure( $data );

		// Episodes without distributable audio are excluded from the feed.
		if ( '' === $enclosure['url'] || ! $enclosure['distributable'] ) {
			return '';
		}

		$title   = self::plain_text( (string) $data['title'] );
		$summary = self::episode_summary( $post, $data );
		$html    = self::episode_html( $post, $data );

		$x   = [];
		$x[] = "\t<item>";
		$x[] = "\t\t<title>" . epm_esc_xml( $title ) . '</title>';
		$x[] = "\t\t<itunes:title>" . epm_esc_xml( $title ) . '</itunes:title>';
		$x[] = "\t\t<link>" . self::xml_url( (string) $data['url'] ) . '</link>';
		$x[] = "\t\t" . '<guid isPermaLink="false">' . epm_esc_xml( (string) $data['guid'] ) . '</guid>';
		$x[] = "\t\t<pubDate>" . epm_esc_xml( (string) $data['date_rfc2822'] ) . '</pubDate>';
		$x[] = "\t\t<description>" . epm_esc_xml( $summary ) . '</description>';
		if ( '' !== $html ) {
			$x[] = "\t\t<content:encoded>" . self::cdata( $html ) . '</content:encoded>';
		}
		$x[] = "\t\t<itunes:summary>" . epm_esc_xml( $summary ) . '</itunes:summary>';
		$x[] = "\t\t" . '<enclosure url="' . self::xml_url( Hosting::measured_url( $enclosure['url'] ) ) . '" length="' . (int) $enclosure['length'] . '" type="' . epm_esc_xml( $enclosure['type'] ) . '" />';

		$seconds = (int) $data['duration_seconds'];
		if ( $seconds > 0 ) {
			$x[] = "\t\t<itunes:duration>" . $seconds . '</itunes:duration>';
		}

		$episode_number = (int) $data['episode_number'];
		if ( $episode_number > 0 ) {
			$x[] = "\t\t<itunes:episode>" . $episode_number . '</itunes:episode>';
		}
		$season_number = (int) $data['season_number'];
		if ( $season_number > 0 ) {
			$x[] = "\t\t<itunes:season>" . $season_number . '</itunes:season>';
		}

		$type = in_array( $data['episode_type'], [ 'full', 'trailer', 'bonus' ], true ) ? $data['episode_type'] : 'full';
		$x[]  = "\t\t<itunes:episodeType>" . $type . '</itunes:episodeType>';
		$x[]  = "\t\t<itunes:explicit>" . ( 'explicit' === $data['explicit'] ? 'true' : 'false' ) . '</itunes:explicit>';

		$item_artwork = self::item_artwork_url( $data );
		if ( '' !== $item_artwork ) {
			$x[] = "\t\t" . '<itunes:image href="' . self::xml_url( $item_artwork ) . '" />';
		}

		// Timed files first (apps use WebVTT/SRT for captions), then the
		// readable HTML page.
		foreach ( (array) ( $data['transcript_files'] ?? [] ) as $file ) {
			if ( ! is_array( $file ) || '' === (string) ( $file['url'] ?? '' ) || '' === (string) ( $file['type'] ?? '' ) ) {
				continue;
			}
			$captions = in_array( $file['type'], [ 'text/vtt', 'application/x-subrip' ], true ) ? ' rel="captions"' : '';
			$x[]      = "\t\t" . '<podcast:transcript url="' . self::xml_url( (string) $file['url'] ) . '" type="' . epm_esc_xml( (string) $file['type'] ) . '"' . $captions . ' />';
		}
		if ( '' !== trim( (string) $data['transcript'] ) ) {
			$x[] = "\t\t" . '<podcast:transcript url="' . self::xml_url( self::transcript_url( (int) $data['id'] ) ) . '" type="text/html" />';
		}
		if ( ! empty( $data['chapters'] ) ) {
			$x[] = "\t\t" . '<podcast:chapters url="' . self::xml_url( self::chapters_url( (int) $data['id'] ) ) . '" type="application/json+chapters" />';
		}

		$guest = self::plain_text( (string) $data['guest_name'] );
		if ( '' !== $guest ) {
			$guest_img = (int) $data['guest_image_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $data['guest_image_id'], 'medium' ) : '';
			$x[]       = "\t\t" . '<podcast:person role="guest"' . ( '' !== $guest_img ? ' img="' . self::xml_url( $guest_img ) . '"' : '' ) . '>' . epm_esc_xml( $guest ) . '</podcast:person>';
		}

		$x[] = "\t</item>";

		return implode( "\n", $x );
	}

	/**
	 * Episode-level artwork for the feed.
	 *
	 * Only episode-specific images qualify (episodes without one inherit the
	 * channel artwork), and only square JPEG/PNG images: directories reject
	 * non-square episode art, e.g. a 16:9 featured image.
	 *
	 * @param array<string, mixed> $data Episode data.
	 * @return string URL or empty string.
	 */
	public static function item_artwork_url( array $data ): string {
		$id = (int) ( $data['own_artwork_id'] ?? 0 );

		if ( $id <= 0 ) {
			// Imported episodes may keep the host's image; the host already
			// served it as episode art, so it is passed through.
			return (string) ( $data['own_artwork_url'] ?? '' );
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			return '';
		}

		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) && (int) $meta['width'] !== (int) $meta['height'] ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'full' );

		return $url ? (string) $url : '';
	}

	/**
	 * Plain-text episode summary: short description → excerpt → trimmed content.
	 *
	 * @param \WP_Post             $post Episode post.
	 * @param array<string, mixed> $data Episode data.
	 * @return string
	 */
	public static function episode_summary( \WP_Post $post, array $data ): string {
		$summary = self::plain_text( (string) $data['short_description'] );

		if ( '' === $summary ) {
			$summary = self::plain_text( (string) $post->post_excerpt );
		}

		if ( '' === $summary ) {
			$content = strip_shortcodes( excerpt_remove_blocks( (string) $post->post_content ) );
			$summary = self::plain_text( wp_trim_words( wp_strip_all_tags( $content ), 55, '…' ) );
		}

		return $summary;
	}

	/**
	 * Rich episode notes for <content:encoded>: the episode description
	 * followed by the show notes, limited to tags podcast apps render.
	 *
	 * @param \WP_Post             $post Episode post.
	 * @param array<string, mixed> $data Episode data.
	 * @return string
	 */
	public static function episode_html( \WP_Post $post, array $data ): string {
		$content = (string) $post->post_content;

		if ( has_blocks( $content ) ) {
			$content = do_blocks( $content );
		} else {
			$content = wpautop( $content );
		}
		$content = strip_shortcodes( $content );

		$notes = trim( (string) $data['show_notes'] );
		if ( '' !== $notes ) {
			$content .= "\n" . wpautop( $notes );
		}

		$html = trim( wp_kses( $content, self::allowed_html() ) );

		return (string) apply_filters( 'epm_feed_episode_html', $html, $post, $data );
	}

	/**
	 * HTML allowed in <content:encoded>.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$link = [
			'href'  => true,
			'title' => true,
			'rel'   => true,
		];

		return [
			'p'          => [],
			'br'         => [],
			'a'          => $link,
			'ul'         => [],
			'ol'         => [],
			'li'         => [],
			'strong'     => [],
			'b'          => [],
			'em'         => [],
			'i'          => [],
			'blockquote' => [],
			'h2'         => [],
			'h3'         => [],
			'h4'         => [],
			'code'       => [],
			'pre'        => [],
		];
	}

	/**
	 * Serve an episode's chapters as a Podcasting 2.0 JSON chapters document.
	 *
	 * @param int $episode_id Episode ID.
	 * @return void
	 */
	private function serve_chapters( int $episode_id ): void {
		$data = epm()->episodes->get_public_data( $episode_id );

		if ( ! $data || empty( $data['chapters'] ) ) {
			self::not_found();
			return;
		}

		$chapters = [];
		foreach ( $data['chapters'] as $chapter ) {
			$entry = [
				'startTime' => (int) $chapter['seconds'],
				'title'     => self::plain_text( (string) $chapter['title'] ),
			];
			if ( '' !== (string) $chapter['url'] ) {
				$entry['url'] = (string) $chapter['url'];
			}
			$chapters[] = $entry;
		}

		$document = [
			'version'     => '1.2.0',
			'title'       => self::plain_text( (string) $data['title'] ),
			'podcastName' => self::plain_text( (string) epm()->settings->get( 'title' ) ),
			'chapters'    => $chapters,
		];

		status_header( 200 );
		header( 'Content-Type: application/json+chapters; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
	}

	/**
	 * Serve an episode's transcript as a minimal HTML document.
	 *
	 * @param int $episode_id Episode ID.
	 * @return void
	 */
	private function serve_transcript( int $episode_id ): void {
		$data = epm()->episodes->get_public_data( $episode_id );

		if ( ! $data || '' === trim( (string) $data['transcript'] ) ) {
			self::not_found();
			return;
		}

		$title = self::plain_text( (string) $data['title'] );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		echo '<!DOCTYPE html><html lang="' . esc_attr( self::rss_language( (string) epm()->settings->get( 'language' ) ) ) . '"><head><meta charset="utf-8">';
		echo '<title>' . esc_html( sprintf( /* translators: %s: episode title */ __( 'Transcript: %s', 'elementor-podcast-manager' ), $title ) ) . '</title>';
		echo '<link rel="canonical" href="' . esc_url( (string) $data['url'] ) . '"></head><body>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo wp_kses_post( wpautop( (string) $data['transcript'] ) );
		echo '</body></html>';
	}

	/**
	 * Send a plain 404 for companion documents.
	 *
	 * @return void
	 */
	private static function not_found(): void {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html__( 'Not found.', 'elementor-podcast-manager' );
	}
}
