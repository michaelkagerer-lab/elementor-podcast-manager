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
 * The rendered XML is cached and invalidated whenever an episode, its
 * media or the podcast settings change; responses carry ETag and
 * Last-Modified headers and answer conditional requests with 304, so
 * podcast apps polling the feed stay cheap.
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
	 * Transient holding the rendered feed.
	 */
	private const CACHE_KEY = 'epm_feed_cache';

	/**
	 * Option holding the immutable podcast:guid.
	 */
	public const GUID_OPTION = 'epm_podcast_guid';

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
		foreach ( [ PodcastSettings::OPTION, 'permalink_structure', 'home', 'blog_charset' ] as $option ) {
			add_action( 'update_option_' . $option, [ self::class, 'flush_cache' ] );
			add_action( 'add_option_' . $option, [ self::class, 'flush_cache' ] );
		}
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
	}

	/**
	 * Add the query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
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
			esc_url( self::url() )
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
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Wrap HTML in a CDATA section (splitting any "]]>" it contains).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function cdata( string $html ): string {
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
			$name = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', self::url() ), '/' );
			$guid = self::uuid_v5( self::GUID_NAMESPACE, $name );
			update_option( self::GUID_OPTION, $guid, false );
		}

		return $guid;
	}

	/**
	 * Get the episodes eligible for the feed, honoring the feed-window policy.
	 *
	 * Policy (documented in README + settings UI): the feed contains the
	 * newest N episodes with distribution-ready audio, where N is the
	 * "Feed episode limit" setting (0 = unlimited). Episodes without audio
	 * or with non-distribution audio (e.g. WAV) never displace eligible
	 * episodes — filtering happens before the window is applied.
	 * Serial podcasts are ordered oldest-first; episodic newest-first.
	 *
	 * @return array<int, \WP_Post>
	 */
	public function eligible_episodes(): array {
		$settings = epm()->settings;
		$limit    = (int) $settings->get( 'feed_limit' );
		$serial   = 'serial' === (string) $settings->get( 'type' );

		$ids = get_posts(
			[
				'post_type'        => EpisodePostType::CPT,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => $serial ? 'ASC' : 'DESC',
				'has_password'     => false,
				'meta_query'       => [
					[
						'key'     => Episodes::META_PREFIX . 'audio_id',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					],
				],
				'no_found_rows'    => true,
			]
		);

		$eligible = [];

		// Prime post, meta and attachment caches once for the whole window.
		_prime_post_caches( array_map( 'intval', $ids ) );
		Episodes::prime_attachments_by_ids( $ids );

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$data = epm()->episodes->get_public_data( $post );
			if ( ! $data ) {
				continue;
			}

			$enclosure = AudioMetadata::enclosure( $data );
			if ( ! $enclosure['distributable'] ) {
				continue;
			}

			$eligible[] = $post;

			if ( $limit > 0 && count( $eligible ) >= $limit ) {
				break;
			}
		}

		return $eligible;
	}

	/**
	 * Flush the cached feed.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Flush the cache when an episode or attachment is saved or deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function maybe_flush_for_post( $post_id ): void {
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
		if ( $post instanceof \WP_Post && EpisodePostType::CPT === $post->post_type ) {
			self::flush_cache();
		}
	}

	/**
	 * Flush the cache when episode meta changes outside the editor (REST, CLI).
	 * Only feed-relevant keys count, so editor heartbeats (_edit_lock) never
	 * invalidate the cache.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function maybe_flush_on_meta( $meta_id, $object_id, $meta_key = '' ): void {
		$meta_key = (string) $meta_key;

		if ( 0 !== strpos( $meta_key, Episodes::META_PREFIX ) && '_thumbnail_id' !== $meta_key ) {
			return;
		}

		if ( EpisodePostType::CPT === get_post_type( (int) $object_id ) ) {
			self::flush_cache();
		}
	}

	/**
	 * Get the rendered feed, from cache when possible.
	 *
	 * @return array{xml: string, modified: int, etag: string}
	 */
	public function get_document(): array {
		$use_cache = (bool) apply_filters( 'epm_feed_cache_enabled', true );

		if ( $use_cache ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['xml'], $cached['modified'], $cached['etag'] ) ) {
				return $cached;
			}
		}

		$episodes = $this->eligible_episodes();
		$modified = 0;
		foreach ( $episodes as $post ) {
			$modified = max( $modified, (int) get_post_modified_time( 'U', true, $post ), (int) get_post_time( 'U', true, $post ) );
		}
		if ( 0 === $modified ) {
			$modified = time();
		}

		$xml = $this->build( $episodes, $modified );

		$document = [
			'xml'      => $xml,
			'modified' => $modified,
			'etag'     => '"' . md5( $xml ) . '"',
		];

		if ( $use_cache ) {
			set_transient( self::CACHE_KEY, $document, 12 * HOUR_IN_SECONDS );
		}

		return $document;
	}

	/**
	 * Send the feed with conditional-GET support.
	 *
	 * @return void
	 */
	public function serve_feed(): void {
		$document = $this->get_document();

		$charset = (string) get_option( 'blog_charset' );
		$etag    = $document['etag'];

		status_header( 200 );
		header( 'Content-Type: application/rss+xml; charset=' . ( '' !== $charset ? $charset : 'UTF-8' ), true );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $document['modified'] ) . ' GMT' );
		header( 'X-Content-Type-Options: nosniff' );

		$if_none_match     = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? wp_unslash( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.

		$not_modified = false;
		if ( '' !== $if_none_match ) {
			$not_modified = false !== strpos( $if_none_match, trim( $etag, '"' ) );
		} elseif ( '' !== $if_modified_since ) {
			$since        = strtotime( $if_modified_since );
			$not_modified = false !== $since && $since >= $document['modified'];
		}

		if ( $not_modified ) {
			status_header( 304 );
			return;
		}

		echo $document['xml']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
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
	 * Build the RSS document.
	 *
	 * @param array<int, \WP_Post> $episodes Eligible episodes.
	 * @param int                  $modified Last-build timestamp.
	 * @return string
	 */
	public function build( array $episodes, int $modified ): string {
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
		if ( ! empty( $episodes ) ) {
			$newest = 0;
			foreach ( $episodes as $post ) {
				$newest = max( $newest, (int) get_post_time( 'U', true, $post ) );
			}
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

		$new_feed_url = (string) $settings->get( 'new_feed_url' );
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

		$funding_url = (string) $settings->get( 'funding_url' );
		if ( '' !== $funding_url ) {
			$funding_label = self::plain_text( (string) $settings->get( 'funding_label' ) );
			if ( '' === $funding_label ) {
				$funding_label = __( 'Support the show', 'elementor-podcast-manager' );
			}
			$x[] = "\t" . '<podcast:funding url="' . self::xml_url( $funding_url ) . '">' . epm_esc_xml( $funding_label ) . '</podcast:funding>';
		}

		foreach ( $episodes as $post ) {
			$item = $this->build_item( $post );
			if ( '' !== $item ) {
				$x[] = $item;
			}
		}

		$x[] = '</channel>';
		$x[] = '</rss>';

		return implode( "\n", $x ) . "\n";
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
		$x[] = "\t\t" . '<enclosure url="' . self::xml_url( $enclosure['url'] ) . '" length="' . (int) $enclosure['length'] . '" type="' . epm_esc_xml( $enclosure['type'] ) . '" />';

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

		if ( '' !== trim( (string) $data['transcript'] ) ) {
			$x[] = "\t\t" . '<podcast:transcript url="' . self::xml_url( self::transcript_url( (int) $data['id'] ) ) . '" type="text/html" />';
		}
		if ( ! empty( $data['chapters'] ) ) {
			$x[] = "\t\t" . '<podcast:chapters url="' . self::xml_url( self::chapters_url( (int) $data['id'] ) ) . '" type="application/json+chapters" />';
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
			return '';
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
