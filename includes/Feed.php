<?php
/**
 * Podcast RSS feed (Layer 1). No Elementor dependency.
 *
 * Endpoint: /podcast/feed/
 * RSS 2.0 + iTunes + Podcast namespaces, suitable for submission to
 * Apple Podcasts, Spotify and other directories.
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
	 * Wire the endpoint.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ self::class, 'register_endpoint' ], 10 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	/**
	 * Register the /podcast/feed/ rewrite rule.
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
	 * Add the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Render the feed when requested.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$this->render();
		exit;
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
	 * Escape a URL for XML output.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function xml_url( string $url ): string {
		return epm_esc_xml( esc_url_raw( $url ) );
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
				'post_type'      => EpisodePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => $serial ? 'ASC' : 'DESC',
				'has_password'   => false,
				'meta_query'     => [
					[
						'key'     => Episodes::META_PREFIX . 'audio_id',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					],
				],
				'no_found_rows'  => true,
			]
		);

		$eligible = [];

		// Prime attachment caches once for the whole window (F18).
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
	 * Render the RSS document.
	 *
	 * @return void
	 */
	public function render(): void {
		$settings = epm()->settings;
		$episodes = $this->eligible_episodes();

		header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
		echo '<?xml version="1.0" encoding="' . epm_esc_xml( (string) get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<rss version="2.0"
	xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
	xmlns:podcast="https://podcastindex.org/namespace/1.0"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:atom="http://www.w3.org/2005/Atom">
	<channel>
		<title><?php echo epm_esc_xml( (string) $settings->get( 'title' ) ); ?></title>
		<atom:link href="<?php echo self::xml_url( self::url() ); ?>" rel="self" type="application/rss+xml" />
		<link><?php echo self::xml_url( (string) $settings->get( 'website_url' ) ); ?></link>
		<language><?php echo epm_esc_xml( self::rss_language( (string) $settings->get( 'language' ) ) ); ?></language>
		<copyright><?php echo epm_esc_xml( (string) $settings->get( 'copyright' ) ); ?></copyright>
		<description><?php echo epm_esc_xml( (string) wp_strip_all_tags( (string) $settings->get( 'description' ) ) ); ?></description>
		<itunes:summary><?php echo epm_esc_xml( (string) wp_strip_all_tags( (string) $settings->get( 'description' ) ) ); ?></itunes:summary>
		<itunes:author><?php echo epm_esc_xml( $settings->effective_author() ); ?></itunes:author>
		<itunes:type><?php echo epm_esc_xml( (string) $settings->get( 'type' ) ); ?></itunes:type>
		<itunes:explicit><?php echo 'explicit' === $settings->get( 'explicit' ) ? 'yes' : 'no'; ?></itunes:explicit>
		<itunes:owner>
			<itunes:name><?php echo epm_esc_xml( (string) $settings->get( 'owner_name' ) ); ?></itunes:name>
			<itunes:email><?php echo epm_esc_xml( (string) $settings->get( 'owner_email' ) ); ?></itunes:email>
		</itunes:owner>
		<?php $category = (string) $settings->get( 'category' ); ?>
		<?php if ( '' !== $category ) : ?>
		<itunes:category text="<?php echo epm_esc_xml( $category ); ?>" />
		<?php endif; ?>
		<?php $artwork = $settings->artwork_url( 'full' ); ?>
		<?php if ( '' !== $artwork ) : ?>
		<image>
			<url><?php echo self::xml_url( $artwork ); ?></url>
			<title><?php echo epm_esc_xml( (string) $settings->get( 'title' ) ); ?></title>
			<link><?php echo self::xml_url( (string) $settings->get( 'website_url' ) ); ?></link>
		</image>
		<itunes:image href="<?php echo self::xml_url( $artwork ); ?>" />
		<?php endif; ?>
		<?php foreach ( $episodes as $post ) : ?>
			<?php $this->render_item( $post ); ?>
		<?php endforeach; ?>
	</channel>
</rss>
		<?php
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

		return 'en';
	}

	/**
	 * Render one episode item.
	 *
	 * @param \WP_Post $post Episode post.
	 * @return void
	 */
	private function render_item( \WP_Post $post ): void {
		// Defense in depth: only publicly-visible episodes reach the feed.
		$data = epm()->episodes->get_public_data( $post );

		if ( ! $data ) {
			return;
		}

		$data = apply_filters( 'epm_feed_episode', $data, $post );

		$enclosure = AudioMetadata::enclosure( $data );

		// Episodes without audio are excluded from distribution feeds.
		if ( '' === $enclosure['url'] ) {
			return;
		}

		$description = '' !== (string) $data['short_description']
			? (string) $data['short_description']
			: wp_trim_words( wp_strip_all_tags( (string) $data['description'] ), 55 );
		?>
		<item>
			<title><?php echo epm_esc_xml( (string) $data['title'] ); ?></title>
			<link><?php echo self::xml_url( (string) $data['url'] ); ?></link>
			<guid isPermaLink="false"><?php echo epm_esc_xml( (string) $data['guid'] ); ?></guid>
			<pubDate><?php echo epm_esc_xml( (string) $data['date_rfc2822'] ); ?></pubDate>
			<description><?php echo epm_esc_xml( $description ); ?></description>
			<enclosure url="<?php echo self::xml_url( $enclosure['url'] ); ?>" length="<?php echo epm_esc_xml( (string) $enclosure['length'] ); ?>" type="<?php echo epm_esc_xml( $enclosure['type'] ); ?>" />
			<?php if ( '' !== (string) $data['duration'] ) : ?>
			<itunes:duration><?php echo epm_esc_xml( (string) $data['duration'] ); ?></itunes:duration>
			<?php endif; ?>
			<?php if ( '' !== (string) $data['episode_number'] ) : ?>
			<itunes:episode><?php echo epm_esc_xml( (string) $data['episode_number'] ); ?></itunes:episode>
			<?php endif; ?>
			<?php if ( '' !== (string) $data['season_number'] ) : ?>
			<itunes:season><?php echo epm_esc_xml( (string) $data['season_number'] ); ?></itunes:season>
			<?php endif; ?>
			<itunes:episodeType><?php echo epm_esc_xml( (string) $data['episode_type'] ); ?></itunes:episodeType>
			<itunes:explicit><?php echo 'explicit' === $data['explicit'] ? 'yes' : 'no'; ?></itunes:explicit>
			<?php if ( (int) $data['artwork_id'] > 0 ) : ?>
				<?php $item_artwork = wp_get_attachment_image_url( (int) $data['artwork_id'], 'full' ); ?>
				<?php if ( $item_artwork ) : ?>
			<itunes:image href="<?php echo self::xml_url( $item_artwork ); ?>" />
				<?php endif; ?>
			<?php endif; ?>
		</item>
		<?php
	}
}
