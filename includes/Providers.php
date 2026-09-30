<?php
/**
 * Podcast hosting providers (data only).
 *
 * Used to recognize a host from its feed address, and to give host-specific
 * directions: where the RSS feed address is shown, and how to set up the
 * permanent (301) redirect when a show moves. Filterable through
 * epm_hosting_providers, so agencies can add regional hosts.
 *
 * Host names are trademarks of their owners and only identify the service.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Providers {

	/**
	 * Per-request cache.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * All providers.
	 *
	 * Keys per provider: name, website, feed_example, hosts (hostname
	 * suffixes of feed URLs), generators (substrings of <generator>),
	 * feed_help, redirect_help.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		// [ name, website, feed example, feed hostnames, generator needles,
		// where the dashboard shows the feed (verified paths only) ].
		$table = [
			'spotify'      => [ 'Spotify for Creators', 'https://creators.spotify.com/', 'https://anchor.fm/s/123abc/podcast/rss', [ 'anchor.fm', 'podcasters.spotify.com', 'creators.spotify.com' ], [ 'anchor', 'spotify for' ], __( 'On creators.spotify.com open Settings → Availability and scroll to “RSS distribution”. The section appears after your first episode is published, and RSS has to be turned on there. Shows without RSS distribution have no public feed.', 'elementor-podcast-manager' ) ],
			'buzzsprout'   => [ 'Buzzsprout', 'https://www.buzzsprout.com/', 'https://feeds.buzzsprout.com/123456.rss', [ 'buzzsprout.com' ], [ 'buzzsprout' ], __( 'Directories page → “RSS Feed” tab → copy.', 'elementor-podcast-manager' ) ],
			'libsyn'       => [ 'Libsyn', 'https://libsyn.com/', 'https://feeds.libsyn.com/123456/rss', [ 'libsyn.com', 'libsyn.net' ], [ 'libsyn' ], __( 'Destinations tab → “View Feed” next to “Libsyn Classic Feed” (also under Quick Links).', 'elementor-podcast-manager' ) ],
			'podbean'      => [ 'Podbean', 'https://www.podbean.com/', 'https://feed.podbean.com/yourshow/feed.xml', [ 'podbean.com' ], [ 'podbean' ], __( 'Podcast Dashboard → Settings → Feed; the RSS feed link is at the top.', 'elementor-podcast-manager' ) ],
			'transistor'   => [ 'Transistor', 'https://transistor.fm/', 'https://feeds.transistor.fm/your-show', [ 'transistor.fm', 'transistor.network' ], [ 'transistor' ], __( 'The show’s Distribution page (the feed is at the top; it is also on Overview).', 'elementor-podcast-manager' ) ],
			'captivate'    => [ 'Captivate', 'https://www.captivate.fm/', 'https://feeds.captivate.fm/your-show/', [ 'captivate.fm' ], [ 'captivate' ], __( '“Copy Feed URL” in the show header, or at the top of the Distribution screen. Keep the trailing slash.', 'elementor-podcast-manager' ) ],
			'rss-com'      => [ 'RSS.com', 'https://rss.com/', 'https://media.rss.com/your-show/feed.xml', [ 'rss.com' ], [ 'rss.com' ], __( 'The “RSS Feed” button on the dashboard copies the address.', 'elementor-podcast-manager' ) ],
			'acast'        => [ 'Acast', 'https://www.acast.com/', 'https://feeds.acast.com/public/shows/your-show', [ 'acast.com', 'pippa.io' ], [ 'acast' ], __( 'Open the show → Distribution (the RSS address is at the bottom), or Share Links → RSS Feed.', 'elementor-podcast-manager' ) ],
			'simplecast'   => [ 'Simplecast', 'https://www.simplecast.com/', 'https://feeds.simplecast.com/abcd1234', [ 'simplecast.com' ], [ 'simplecast' ], __( 'Show settings (gear icon) → Distribution → RSS.', 'elementor-podcast-manager' ) ],
			'megaphone'    => [ 'Megaphone', 'https://megaphone.spotify.com/', 'https://feeds.megaphone.fm/ABC1234567', [ 'megaphone.fm' ], [ 'megaphone' ], __( 'Podcast Library → your podcast → the “Feed” button.', 'elementor-podcast-manager' ) ],
			'omny'         => [ 'Omny Studio', 'https://omnystudio.com/', 'https://www.omnycontent.com/d/playlist/…/podcast.rss', [ 'omnycontent.com', 'omny.fm', 'omnystudio.com' ], [ 'omny' ], __( 'Programs → your program → Playlists → the playlist → Details → RSS feed “Copy to clipboard”.', 'elementor-podcast-manager' ) ],
			'podigee'      => [ 'Podigee', 'https://www.podigee.com/', 'https://yourshow.podigee.io/feed/mp3', [ 'podigee.io', 'podigee.com', 'podigee-cdn.net' ], [ 'podigee' ], __( 'Edit podcast → Feeds → copy the MP3 feed.', 'elementor-podcast-manager' ) ],
			'letscast'     => [ 'LetsCast.fm', 'https://letscast.fm/', 'https://letscast.fm/podcasts/your-show-1a2b3c4d/feed', [ 'letscast.fm' ], [ 'letscast' ], '' ],
			'podcaster-de' => [ 'podcaster.de', 'https://www.podcaster.de/', 'https://yourshow.podcaster.de/yourshow.rss', [ 'podcaster.de' ], [ 'podcaster.de', 'feedarator' ], '' ],
			'julep'        => [ 'Julep', 'https://julephosting.de/', 'https://cdn.julephosting.de/podcasts/123-your-show/feed.rss', [ 'julephosting.de', 'audiorella.com' ], [ 'julep' ], '' ],
			'castos'       => [ 'Castos', 'https://castos.com/', 'https://feeds.castos.com/abc123', [ 'castos.com' ], [ 'castos' ], __( 'Podcast Settings → Distribution → Visibility → “Public Podcast RSS Feed” → Copy.', 'elementor-podcast-manager' ) ],
			'blubrry'      => [ 'Blubrry', 'https://blubrry.com/', 'https://feeds.blubrry.com/feeds/yourshow.xml', [ 'blubrry.com', 'blubrry.net' ], [ 'blubrry', 'rawvoice' ], __( 'Podcaster Dashboard → Podcast Hosting → Hosting Settings; the feed is above Save.', 'elementor-podcast-manager' ) ],
			'spreaker'     => [ 'Spreaker', 'https://www.spreaker.com/', 'https://www.spreaker.com/show/1234567/episodes/feed', [ 'spreaker.com' ], [ 'spreaker' ], __( 'Dashboard → your podcast → “RSS Customization”.', 'elementor-podcast-manager' ) ],
			'redcircle'    => [ 'RedCircle', 'https://redcircle.com/', 'https://feeds.redcircle.com/0000aaaa-bbbb-cccc-dddd-eeeeffff0000', [ 'redcircle.com' ], [ 'redcircle' ], __( 'On the show page, click the copy icon under the description.', 'elementor-podcast-manager' ) ],
			'riverside'    => [ 'Riverside', 'https://riverside.fm/', 'https://api.riverside.fm/hosting/abc123.rss', [ 'api.riverside.fm', 'api.riverside.com' ], [ 'riverside' ], '' ],
			'ausha'        => [ 'Ausha', 'https://www.ausha.co/', 'https://feed.ausha.co/abc123', [ 'ausha.co' ], [ 'ausha' ], '' ],
			'zencastr'     => [ 'Zencastr', 'https://zencastr.com/', 'https://feeds.zencastr.com/f/abc123.rss', [ 'zencastr.com' ], [ 'zencastr' ], '' ],
			'art19'        => [ 'ART19', 'https://art19.com/', 'https://rss.art19.com/your-show', [ 'art19.com' ], [ 'art19' ], '' ],
			'audioboom'    => [ 'Audioboom', 'https://audioboom.com/', 'https://audioboom.com/channels/1234567.rss', [ 'audioboom.com' ], [ 'audioboom' ], '' ],
			'soundcloud'   => [ 'SoundCloud', 'https://soundcloud.com/', 'https://feeds.soundcloud.com/users/soundcloud:users:123456/sounds.rss', [ 'soundcloud.com' ], [ 'soundcloud' ], '' ],
			'fireside'     => [ 'Fireside', 'https://fireside.fm/', 'https://feeds.fireside.fm/your-show/rss', [ 'fireside.fm' ], [ 'fireside' ], '' ],
			'substack'     => [ 'Substack', 'https://substack.com/', 'https://api.substack.com/feed/podcast/123456.rss', [ 'substack.com' ], [ 'substack' ], __( 'Use the public podcast feed only: paid subscribers’ private feeds must not be imported.', 'elementor-podcast-manager' ) ],
			'squarespace'  => [ 'Squarespace', 'https://www.squarespace.com/', 'https://example.com/podcast?format=rss', [ 'squarespace.com' ], [ 'squarespace' ], '' ],
			'wordpress'    => [ __( 'Another WordPress site', 'elementor-podcast-manager' ), '', 'https://example.com/feed/podcast/', [], [ 'wordpress', 'powerpress', 'podlove', 'seriously simple' ], __( 'PowerPress and Seriously Simple Podcasting publish the feed at /feed/podcast/ on the site; their settings screens show the exact address.', 'elementor-podcast-manager' ) ],
			'other'        => [ __( 'Another host', 'elementor-podcast-manager' ), '', 'https://…/feed.xml', [], [], '' ],
		];

		$generic_feed = __( 'Every podcast host shows the RSS feed address in its dashboard, usually under Distribution, Directories or Settings. It is the address that was submitted to Apple Podcasts. You can also paste your show’s Apple Podcasts link or its web page.', 'elementor-podcast-manager' );

		$providers = [];
		foreach ( $table as $id => [ $name, $website, $example, $hosts, $generators, $feed_help ] ) {
			$providers[ $id ] = [
				'name'          => $name,
				'website'       => $website,
				'feed_example'  => $example,
				'hosts'         => $hosts,
				'generators'    => $generators,
				'feed_help'     => '' !== $feed_help ? $feed_help : $generic_feed,
				'redirect_help' => self::redirect_help( $id, $name ),
			];
		}

		self::$cache = (array) apply_filters( 'epm_hosting_providers', $providers );

		return self::$cache;
	}

	/**
	 * How to point a host's feed at this site with a 301 redirect.
	 *
	 * Only Spotify for Creators' steps are spelled out (documented by
	 * Spotify); menus of other hosts change too often to name reliably.
	 *
	 * @param string $id   Provider ID.
	 * @param string $name Provider name.
	 * @return string
	 */
	private static function redirect_help( string $id, string $name ): string {
		if ( 'spotify' === $id ) {
			return __( 'On the web (not in the app): log in to creators.spotify.com → Settings → “Redirect your podcast” → paste this site’s feed address → Redirect. It can take up to 7 days; keep the account until it is done, and turn off Subscriptions first if you use them.', 'elementor-podcast-manager' );
		}

		if ( 'wordpress' === $id ) {
			return __( 'Use your podcast plugin’s feed redirect setting, or a redirect plugin, to send the old feed address to this site’s feed address with a permanent (301) redirect.', 'elementor-podcast-manager' );
		}

		if ( 'other' === $id ) {
			return __( 'In your host’s settings, find the option that redirects or moves the feed (often called “301 redirect”, “Redirect feed” or “Move podcast”) and paste this site’s feed address. If you cannot find it, the host’s support can set the redirect for you.', 'elementor-podcast-manager' );
		}

		return sprintf(
			/* translators: %s: podcast host name */
			__( 'In %s, find the setting that redirects or moves the feed (often called “301 redirect”, “Redirect feed” or “Move podcast”) and paste this site’s feed address. If you cannot find it, the host’s support can set the redirect for you.', 'elementor-podcast-manager' ),
			$name
		);
	}

	/**
	 * One provider.
	 *
	 * @param string $id Provider ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Recognize a host from its feed address.
	 *
	 * @param string $url Feed URL.
	 * @return string|null Provider ID.
	 */
	public static function detect( string $url ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return null;
		}

		foreach ( self::all() as $id => $provider ) {
			foreach ( (array) ( $provider['hosts'] ?? [] ) as $suffix ) {
				$suffix = strtolower( (string) $suffix );
				if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
					return (string) $id;
				}
			}
		}

		return null;
	}

	/**
	 * Recognize a host from the feed's <generator> text.
	 *
	 * @param string $generator Generator text.
	 * @return string|null Provider ID.
	 */
	public static function detect_generator( string $generator ): ?string {
		$generator = strtolower( trim( $generator ) );
		if ( '' === $generator ) {
			return null;
		}

		foreach ( self::all() as $id => $provider ) {
			foreach ( (array) ( $provider['generators'] ?? [] ) as $needle ) {
				if ( '' !== $needle && false !== strpos( $generator, strtolower( (string) $needle ) ) ) {
					return (string) $id;
				}
			}
		}

		return null;
	}

	/**
	 * Options for a <select>, the generic entries last.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$out = [];
		foreach ( self::all() as $id => $provider ) {
			if ( in_array( $id, [ 'wordpress', 'other' ], true ) ) {
				continue;
			}
			$out[ $id ] = (string) $provider['name'];
		}
		foreach ( [ 'wordpress', 'other' ] as $id ) {
			$provider = self::get( $id );
			if ( null !== $provider ) {
				$out[ $id ] = (string) $provider['name'];
			}
		}

		return $out;
	}
}
