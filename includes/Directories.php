<?php
/**
 * Listening platforms and link services (data only).
 *
 * One registry for:
 * - the distribution checklist (where to submit the feed, in which order,
 *   what each platform checks, and which apps follow automatically), and
 * - the "listen on" / social link services (labels, icons, and detection
 *   of the service from a pasted URL).
 *
 * Filterable through epm_directories and epm_link_services. Platform
 * names are trademarks of their owners and only identify the service.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Directories {

	/**
	 * Option: per-directory status (submitted/listed) and listing URLs.
	 */
	public const OPTION = 'epm_distribution';

	/**
	 * Directories where a show can be submitted.
	 *
	 * Keys per entry: name, icon, priority (essential|recommended|optional),
	 * submit_url, steps, needs, via (another directory that lists the show
	 * automatically, '' when a submission is needed), region, service (link
	 * service key for the listing URL).
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function all(): array {
		$owner_email = __( 'The owner email must be in the feed: the platform sends a verification code to it.', 'elementor-podcast-manager' );

		$directories = [
			'apple'         => [
				'name'       => 'Apple Podcasts',
				'icon'       => 'apple',
				'priority'   => 'essential',
				'submit_url' => 'https://podcastsconnect.apple.com/',
				'steps'      => __( 'Sign in to Podcasts Connect with your Apple Account, add a new show, choose “Add a show with an RSS feed”, paste your feed address and submit it for review.', 'elementor-podcast-manager' ),
				'needs'      => __( 'Square JPEG or PNG artwork of 1400–3000 px, a category, an owner email and at least one episode. Review usually takes a few days.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'apple',
			],
			'spotify'       => [
				'name'       => 'Spotify',
				'icon'       => 'spotify',
				'priority'   => 'essential',
				'submit_url' => 'https://creators.spotify.com/',
				'steps'      => __( 'On Spotify for Creators choose “Find an existing show” → “Somewhere else”, paste your feed address, enter the 8-digit code Spotify emails to the feed’s owner address, then confirm country, language and category.', 'elementor-podcast-manager' ),
				'needs'      => $owner_email . ' ' . __( 'Artwork must be exactly square.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'spotify',
			],
			'youtube'       => [
				'name'       => 'YouTube & YouTube Music',
				'icon'       => 'youtube',
				'priority'   => 'essential',
				'submit_url' => 'https://studio.youtube.com/',
				'steps'      => __( 'In YouTube Studio choose Create → New podcast → Submit RSS feed, accept the terms, send the verification code to the feed’s email, pick the episodes and publish the podcast once processing is done (it starts as private).', 'elementor-podcast-manager' ),
				'needs'      => $owner_email . ' ' . __( 'YouTube turns the artwork into still-image videos. Dynamically inserted ads are not allowed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'youtube',
			],
			'amazon'        => [
				'name'       => 'Amazon Music & Audible',
				'icon'       => '',
				'priority'   => 'essential',
				'submit_url' => 'https://podcasters.amazon.com/',
				'steps'      => __( 'On Amazon Music for Podcasters choose “Get started”, sign in with an Amazon account, paste your feed address, pick a country and confirm the email sent to the feed’s address. One submission lists the show on Amazon Music and Audible.', 'elementor-podcast-manager' ),
				'needs'      => $owner_email,
				'via'        => '',
				'region'     => '',
				'service'    => 'amazon',
			],
			'podcastindex'  => [
				'name'       => 'Podcast Index',
				'icon'       => 'podcastindex',
				'priority'   => 'essential',
				'submit_url' => 'https://podcastindex.org/add',
				'steps'      => __( 'Paste your feed address; the show appears within minutes. Podcast Index supplies Fountain, Podverse, Castamatic, Podcast Guru, AntennaPod search and many other apps.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'podcastindex',
			],
			'iheart'        => [
				'name'       => 'iHeartRadio',
				'icon'       => 'iheart',
				'priority'   => 'recommended',
				'submit_url' => 'https://podcasters.iheart.com/',
				'steps'      => __( 'Sign in, choose “Add Your Podcast”, paste your feed address, accept the terms and confirm the email sent to the feed’s address.', 'elementor-podcast-manager' ),
				'needs'      => $owner_email,
				'via'        => '',
				'region'     => '',
				'service'    => 'iheart',
			],
			'pocketcasts'   => [
				'name'       => 'Pocket Casts',
				'icon'       => 'pocketcasts',
				'priority'   => 'recommended',
				'submit_url' => 'https://pocketcasts.com/submit/',
				'steps'      => __( 'Paste your feed address and submit. It can take up to about 12 hours.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'pocketcasts',
			],
			'deezer'        => [
				'name'       => 'Deezer',
				'icon'       => 'deezer',
				'priority'   => 'recommended',
				'submit_url' => 'https://podcasters.deezer.com/',
				'steps'      => __( 'Choose “Publish my podcast”, paste your feed address, verify it with the code emailed to the feed’s address and fill in the show details.', 'elementor-podcast-manager' ),
				'needs'      => $owner_email,
				'via'        => '',
				'region'     => '',
				'service'    => 'deezer',
			],
			'podcastaddict' => [
				'name'       => 'Podcast Addict',
				'icon'       => 'podcastaddict',
				'priority'   => 'recommended',
				'submit_url' => 'https://podcastaddict.com/submit',
				'steps'      => __( 'Search for the show first; if it is missing, paste your feed address and submit.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'podcastaddict',
			],
			'pandora'       => [
				'name'       => 'Pandora & SiriusXM',
				'icon'       => 'pandora',
				'priority'   => 'optional',
				'submit_url' => 'https://auth.simplecast.com/signup?connect=true',
				'steps'      => __( 'Sign up for Simplecast Creator Connect, choose “Add Shows”, paste your feed address, accept the SiriusXM/Pandora terms and confirm the email sent to the feed’s address.', 'elementor-podcast-manager' ),
				'needs'      => $owner_email . ' ' . __( 'Your server must answer HEAD and byte-range requests for audio.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => __( 'United States', 'elementor-podcast-manager' ),
				'service'    => 'pandora',
			],
			'tunein'        => [
				'name'       => 'TuneIn',
				'icon'       => '',
				'priority'   => 'optional',
				'submit_url' => 'https://broadcasters.tunein.com/podcasts/add',
				'steps'      => __( 'Check whether the show is listed already, then fill in the “Add a Podcast” form. Review takes days to weeks. TuneIn also supplies Alexa speakers.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'tunein',
			],
			'podcastde'     => [
				'name'       => 'podcast.de',
				'icon'       => '',
				'priority'   => 'optional',
				'submit_url' => 'https://www.podcast.de/podcast-anmelden',
				'steps'      => __( 'Fill in the registration form with your feed address; no account needed.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => __( 'German-speaking countries', 'elementor-podcast-manager' ),
				'service'    => 'custom',
			],
			'listennotes'   => [
				'name'       => 'Listen Notes',
				'icon'       => '',
				'priority'   => 'optional',
				'submit_url' => 'https://www.listennotes.com/submit/',
				'steps'      => __( 'Paste your feed address.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => '',
				'region'     => '',
				'service'    => 'custom',
			],
			'overcast'      => [
				'name'       => 'Overcast',
				'icon'       => 'overcast',
				'priority'   => 'optional',
				'submit_url' => 'https://overcast.fm/podcasterinfo',
				'steps'      => __( 'Nothing to submit: the show appears one or two days after Apple Podcasts lists it.', 'elementor-podcast-manager' ),
				'needs'      => __( 'An Apple Podcasts listing.', 'elementor-podcast-manager' ),
				'via'        => 'apple',
				'region'     => '',
				'service'    => 'overcast',
			],
			'castro'        => [
				'name'       => 'Castro',
				'icon'       => 'castro',
				'priority'   => 'optional',
				'submit_url' => '',
				'steps'      => __( 'Nothing to submit: Castro uses the Apple Podcasts directory.', 'elementor-podcast-manager' ),
				'needs'      => __( 'An Apple Podcasts listing.', 'elementor-podcast-manager' ),
				'via'        => 'apple',
				'region'     => '',
				'service'    => 'castro',
			],
			'castbox'       => [
				'name'       => 'Castbox',
				'icon'       => 'castbox',
				'priority'   => 'optional',
				'submit_url' => 'https://castbox.fm/',
				'steps'      => __( 'Nothing to submit: Castbox picks the show up from Apple Podcasts. Claim it in Castbox Creator Studio to manage it.', 'elementor-podcast-manager' ),
				'needs'      => __( 'An Apple Podcasts listing.', 'elementor-podcast-manager' ),
				'via'        => 'apple',
				'region'     => '',
				'service'    => 'castbox',
			],
			'goodpods'      => [
				'name'       => 'Goodpods',
				'icon'       => '',
				'priority'   => 'optional',
				'submit_url' => 'https://goodpods.com/claim-podcast',
				'steps'      => __( 'Nothing to submit: Goodpods lists shows from Apple Podcasts. Claim yours from your Goodpods profile.', 'elementor-podcast-manager' ),
				'needs'      => __( 'An Apple Podcasts listing.', 'elementor-podcast-manager' ),
				'via'        => 'apple',
				'region'     => '',
				'service'    => 'goodpods',
			],
			'playerfm'      => [
				'name'       => 'Player FM',
				'icon'       => 'playerfm',
				'priority'   => 'optional',
				'submit_url' => 'https://player.fm/',
				'steps'      => __( 'Usually automatic. If the show is missing, paste your feed address into Player FM’s search.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A public feed.', 'elementor-podcast-manager' ),
				'via'        => 'apple',
				'region'     => '',
				'service'    => 'playerfm',
			],
			'fountain'      => [
				'name'       => 'Fountain',
				'icon'       => '',
				'priority'   => 'optional',
				'submit_url' => '',
				'steps'      => __( 'Nothing to submit: Fountain lists shows from Podcast Index within minutes.', 'elementor-podcast-manager' ),
				'needs'      => __( 'A Podcast Index listing.', 'elementor-podcast-manager' ),
				'via'        => 'podcastindex',
				'region'     => '',
				'service'    => 'fountain',
			],
		];

		return (array) apply_filters( 'epm_directories', $directories );
	}

	/**
	 * One directory.
	 *
	 * @param string $id Directory ID.
	 * @return array<string, string>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Saved progress: [ id => [ 'status' => submitted|listed, 'url' => listing URL ] ].
	 *
	 * @return array<string, array{status: string, url: string}>
	 */
	public static function progress(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Save one directory's progress.
	 *
	 * A listing URL also becomes a platform link (Podcast settings →
	 * platform links) unless one for that service exists already, so the
	 * subscribe buttons fill themselves as the show gets listed.
	 *
	 * @param string $id     Directory ID.
	 * @param string $status ''|submitted|listed.
	 * @param string $url    Listing URL.
	 * @return array{status: string, url: string}
	 */
	public static function save_progress( string $id, string $status, string $url ): array {
		$directory = self::get( $id );
		if ( null === $directory ) {
			return [
				'status' => '',
				'url'    => '',
			];
		}

		$status = in_array( $status, [ 'submitted', 'listed' ], true ) ? $status : '';
		$url    = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' !== $url && '' === $status ) {
			$status = 'listed';
		}

		$progress        = self::progress();
		$progress[ $id ] = [
			'status' => $status,
			'url'    => $url,
		];
		if ( '' === $status && '' === $url ) {
			unset( $progress[ $id ] );
		}
		update_option( self::OPTION, $progress, false );

		if ( '' !== $url ) {
			self::add_platform_link( (string) $directory['service'], (string) $directory['name'], $url );
		}

		return [
			'status' => $status,
			'url'    => $url,
		];
	}

	/**
	 * Add a platform link to the podcast settings when the service has none.
	 *
	 * @param string $service Service key.
	 * @param string $label   Label.
	 * @param string $url     URL.
	 * @return bool Whether a link was added.
	 */
	public static function add_platform_link( string $service, string $label, string $url ): bool {
		$settings = epm()->settings;
		$values   = $settings->all();
		$links    = is_array( $values['platform_links'] ) ? $values['platform_links'] : [];

		foreach ( $links as $link ) {
			if ( ( $link['service'] ?? '' ) === $service && 'custom' !== $service ) {
				return false;
			}
			if ( untrailingslashit( (string) ( $link['url'] ?? '' ) ) === untrailingslashit( $url ) ) {
				return false;
			}
		}

		$links[]                  = [
			'service' => $service,
			'label'   => $label,
			'url'     => $url,
		];
		$values['platform_links'] = $links;

		update_option( PodcastSettings::OPTION, $settings->sanitize( $values ) );

		return true;
	}

	/**
	 * Link services for "listen on" and social links.
	 *
	 * Keys per entry: label, icon (BrandIcons key or ''), hosts (hostname
	 * suffixes that identify the service in a pasted URL), group
	 * (listen|social|support|other).
	 *
	 * @return array<string, array{label: string, icon: string, hosts: array<int, string>, group: string}>
	 */
	public static function services(): array {
		$services = [
			'apple'         => [ 'Apple Podcasts', 'apple', [ 'podcasts.apple.com', 'itunes.apple.com' ], 'listen' ],
			'spotify'       => [ 'Spotify', 'spotify', [ 'open.spotify.com', 'spotify.link' ], 'listen' ],
			'youtube'       => [ 'YouTube', 'youtube', [ 'youtube.com', 'youtu.be' ], 'listen' ],
			'youtube-music' => [ 'YouTube Music', 'youtube-music', [ 'music.youtube.com' ], 'listen' ],
			'amazon'        => [ 'Amazon Music', '', [ 'music.amazon.com', 'music.amazon.de', 'music.amazon.co.uk', 'amazon.com' ], 'listen' ],
			'audible'       => [ 'Audible', 'audible', [ 'audible.com', 'audible.de', 'audible.co.uk' ], 'listen' ],
			'pocketcasts'   => [ 'Pocket Casts', 'pocketcasts', [ 'pocketcasts.com', 'pca.st' ], 'listen' ],
			'overcast'      => [ 'Overcast', 'overcast', [ 'overcast.fm' ], 'listen' ],
			'castro'        => [ 'Castro', 'castro', [ 'castro.fm' ], 'listen' ],
			'castbox'       => [ 'Castbox', 'castbox', [ 'castbox.fm' ], 'listen' ],
			'iheart'        => [ 'iHeartRadio', 'iheart', [ 'iheart.com' ], 'listen' ],
			'deezer'        => [ 'Deezer', 'deezer', [ 'deezer.com' ], 'listen' ],
			'pandora'       => [ 'Pandora', 'pandora', [ 'pandora.com' ], 'listen' ],
			'podcastaddict' => [ 'Podcast Addict', 'podcastaddict', [ 'podcastaddict.com' ], 'listen' ],
			'podcastindex'  => [ 'Podcast Index', 'podcastindex', [ 'podcastindex.org' ], 'listen' ],
			'playerfm'      => [ 'Player FM', 'playerfm', [ 'player.fm' ], 'listen' ],
			'antennapod'    => [ 'AntennaPod', 'antennapod', [ 'antennapod.org' ], 'listen' ],
			'fountain'      => [ 'Fountain', '', [ 'fountain.fm' ], 'listen' ],
			'goodpods'      => [ 'Goodpods', '', [ 'goodpods.com' ], 'listen' ],
			'tunein'        => [ 'TuneIn', '', [ 'tunein.com' ], 'listen' ],
			'soundcloud'    => [ 'SoundCloud', 'soundcloud', [ 'soundcloud.com' ], 'listen' ],
			'rss'           => [ 'RSS', 'rss', [], 'listen' ],
			'instagram'     => [ 'Instagram', 'instagram', [ 'instagram.com' ], 'social' ],
			'tiktok'        => [ 'TikTok', 'tiktok', [ 'tiktok.com' ], 'social' ],
			'x'             => [ 'X', 'x', [ 'x.com', 'twitter.com' ], 'social' ],
			'facebook'      => [ 'Facebook', 'facebook', [ 'facebook.com', 'fb.com' ], 'social' ],
			'linkedin'      => [ 'LinkedIn', '', [ 'linkedin.com' ], 'social' ],
			'threads'       => [ 'Threads', 'threads', [ 'threads.net', 'threads.com' ], 'social' ],
			'bluesky'       => [ 'Bluesky', 'bluesky', [ 'bsky.app' ], 'social' ],
			'mastodon'      => [ 'Mastodon', 'mastodon', [], 'social' ],
			'patreon'       => [ 'Patreon', 'patreon', [ 'patreon.com' ], 'support' ],
			'buymeacoffee'  => [ 'Buy Me a Coffee', 'buymeacoffee', [ 'buymeacoffee.com' ], 'support' ],
			'kofi'          => [ 'Ko-fi', 'kofi', [ 'ko-fi.com' ], 'support' ],
			'substack'      => [ 'Substack', 'substack', [ 'substack.com' ], 'support' ],
			'website'       => [ __( 'Website', 'elementor-podcast-manager' ), '', [], 'other' ],
			'custom'        => [ __( 'Other', 'elementor-podcast-manager' ), '', [], 'other' ],
		];

		$out = [];
		foreach ( $services as $key => [ $label, $icon, $hosts, $group ] ) {
			$out[ $key ] = [
				'label' => $label,
				'icon'  => $icon,
				'hosts' => $hosts,
				'group' => $group,
			];
		}

		return (array) apply_filters( 'epm_link_services', $out );
	}

	/**
	 * Recognize the service of a pasted link.
	 *
	 * @param string $url URL.
	 * @return string Service key, 'custom' when unknown.
	 */
	public static function detect_service( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );

		if ( '' === $host ) {
			return 'custom';
		}

		// Most specific hostnames first (music.youtube.com before youtube.com).
		$candidates = [];
		foreach ( self::services() as $key => $service ) {
			foreach ( $service['hosts'] as $suffix ) {
				if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
					$candidates[ $key ] = strlen( $suffix );
				}
			}
		}

		if ( empty( $candidates ) ) {
			return 'custom';
		}

		arsort( $candidates );

		return (string) array_key_first( $candidates );
	}
}
