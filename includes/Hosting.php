<?php
/**
 * Where the podcast is hosted (Layer 1).
 *
 * Two modes:
 *
 * - self:     this website hosts the show. Episodes live here, audio comes
 *             from the Media Library (or any audio URL) and the plugin
 *             publishes the RSS feed that directories read.
 * - external: another host (Spotify for Creators, Buzzsprout, Libsyn, …)
 *             publishes the RSS feed. The plugin mirrors that feed into
 *             episodes (so the site gets pages, players and widgets),
 *             keeps them in sync on a schedule, and sends podcast apps
 *             that request the local feed to the host's feed with a 301.
 *
 * Settings live in one option; sync bookkeeping in a separate,
 * non-autoloaded option so saving the settings form never clobbers it.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hosting {

	public const OPTION = 'epm_hosting';

	public const STATE_OPTION = 'epm_sync_state';

	public const CRON_HOOK = 'epm_sync_feed';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'mode'       => 'self',
			'provider'   => '',
			'feed_url'   => '',
			'sync'       => true,
			'interval'   => 'hourly',
			'new_status' => 'publish',
			'missing'    => 'keep',
			'redirect'   => true,
			// Download statistics: a measurement prefix in front of the
			// audio URLs of this site's feed (self-hosted shows only).
			'stats'      => '',
			'stats_prefix' => '',
		];
	}

	/**
	 * Measurement services that work as an audio URL prefix.
	 *
	 * @return array<string, array{name: string, prefix: string, dashboard: string}>
	 */
	public static function stats_services(): array {
		return (array) apply_filters(
			'epm_stats_services',
			[
				'op3'     => [
					'name'      => 'OP3',
					'prefix'    => 'https://op3.dev/e/',
					'dashboard' => 'https://op3.dev/show/{podcast_guid}',
				],
				'podtrac' => [
					'name'      => 'Podtrac',
					'prefix'    => 'https://dts.podtrac.com/redirect.mp3/',
					'dashboard' => 'https://analytics.podtrac.com/',
				],
			]
		);
	}

	/**
	 * The measurement prefix in use ('' for none).
	 *
	 * @return string
	 */
	public static function stats_prefix(): string {
		$stats = (string) self::get( 'stats' );

		if ( 'custom' === $stats ) {
			return (string) self::get( 'stats_prefix' );
		}

		$services = self::stats_services();

		return isset( $services[ $stats ] ) ? (string) $services[ $stats ]['prefix'] : '';
	}

	/**
	 * An audio URL routed through the measurement prefix.
	 *
	 * "https://" is dropped (prefix services assume it); an explicit
	 * "http://" is kept. URLs that already pass through the same service
	 * are left alone, so imported episodes never get the prefix twice.
	 *
	 * @param string $url Audio URL.
	 * @return string
	 */
	public static function measured_url( string $url ): string {
		$prefix = self::stats_prefix();

		if ( '' === $prefix || '' === $url || self::is_external() ) {
			return $url;
		}

		$prefix_host = strtolower( (string) wp_parse_url( $prefix, PHP_URL_HOST ) );
		if ( '' !== $prefix_host && false !== stripos( $url, $prefix_host ) ) {
			return $url;
		}

		return trailingslashit( $prefix ) . (string) preg_replace( '#^https://#i', '', $url );
	}

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( self::CRON_HOOK, [ self::class, 'run_scheduled_sync' ] );
		add_action( 'update_option_' . self::OPTION, [ self::class, 'on_settings_saved' ], 10, 2 );
		add_action( 'add_option_' . self::OPTION, [ self::class, 'on_settings_added' ], 10, 2 );
		add_action( 'init', [ self::class, 'ensure_schedule' ], 30 );
	}

	/**
	 * Register the option with sanitization.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'epm_hosting_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);

		add_filter( 'option_page_capability_epm_hosting_group', [ Capabilities::class, 'manage_podcast' ] );
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$out = self::defaults();

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['mode']       = 'external' === ( $input['mode'] ?? '' ) ? 'external' : 'self';
		$provider          = sanitize_key( (string) ( $input['provider'] ?? '' ) );
		$out['provider']   = ( '' === $provider || null !== Providers::get( $provider ) ) ? $provider : 'other';
		$out['feed_url']   = self::sanitize_feed_url( (string) ( $input['feed_url'] ?? '' ) );
		$out['sync']       = ! empty( $input['sync'] );
		$out['interval']   = in_array( $input['interval'] ?? '', [ 'hourly', 'twicedaily', 'daily' ], true ) ? $input['interval'] : 'hourly';
		$out['new_status'] = 'draft' === ( $input['new_status'] ?? '' ) ? 'draft' : 'publish';
		$out['missing']    = 'draft' === ( $input['missing'] ?? '' ) ? 'draft' : 'keep';
		$out['redirect']   = ! empty( $input['redirect'] );

		$stats                = sanitize_key( (string) ( $input['stats'] ?? '' ) );
		$out['stats']         = ( 'custom' === $stats || isset( self::stats_services()[ $stats ] ) ) ? $stats : '';
		$prefix               = esc_url_raw( trim( (string) ( $input['stats_prefix'] ?? '' ) ), [ 'https' ] );
		$out['stats_prefix']  = '' !== $prefix ? trailingslashit( $prefix ) : '';
		if ( 'custom' === $out['stats'] && '' === $out['stats_prefix'] ) {
			$out['stats'] = '';
		}

		// Another host needs its feed address. A bad one keeps the address
		// saved before; without one, hosting stays on this website.
		if ( 'external' === $out['mode'] && '' === $out['feed_url'] ) {
			$previous = self::all();
			if ( 'external' === $previous['mode'] && '' !== (string) $previous['feed_url'] ) {
				$out['feed_url'] = (string) $previous['feed_url'];
			} else {
				$out['mode'] = 'self';
			}
			self::report(
				'epm_hosting_feed',
				'self' === $out['mode']
					? __( 'Enter your host’s RSS feed address (it starts with https://) to switch to another host. Hosting stays on this website until then.', 'elementor-podcast-manager' )
					: __( 'Enter your host’s RSS feed address (it starts with https://). The address saved before is kept.', 'elementor-podcast-manager' )
			);
		}

		// Detect the host from the feed address when none was chosen.
		if ( '' === $out['provider'] && '' !== $out['feed_url'] ) {
			$detected        = Providers::detect( $out['feed_url'] );
			$out['provider'] = null !== $detected ? $detected : 'other';
		}

		return $out;
	}

	/**
	 * Show a message on the Hosting screen once (WordPress runs the
	 * sanitizer twice when the option is saved for the first time).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	private static function report( string $code, string $message ): void {
		// Outside the settings form (setup assistant, cron) there is no
		// place to show it.
		if ( ! function_exists( 'add_settings_error' ) || ! function_exists( 'get_settings_errors' ) ) {
			return;
		}

		foreach ( get_settings_errors( 'epm_hosting_group' ) as $existing ) {
			if ( ( $existing['code'] ?? '' ) === $code ) {
				return;
			}
		}

		add_settings_error( 'epm_hosting_group', $code, $message );
	}

	/**
	 * Normalize a feed address a user pasted: trims, adds https:// when the
	 * scheme is missing, rewrites feed:// and podcast:// schemes.
	 *
	 * @param string $url Raw address.
	 * @return string Empty when it is not an http(s) URL.
	 */
	public static function sanitize_feed_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$url = (string) preg_replace( '#^(?:feed|podcast|pcast|itpc)://#i', 'https://', $url );
		$url = (string) preg_replace( '#^feed:(https?://)#i', '$1', $url );

		// Text with spaces, or another scheme (ftp:, mailto:, javascript:),
		// is no feed address. "example.com:8080/feed" is a host with a port.
		if ( preg_match( '/\s/', $url ) || ( ! preg_match( '#^https?://#i', $url ) && preg_match( '#^[a-z][a-z0-9+.-]*://|^[a-z][a-z0-9+-]*:(?!\d)#i', $url ) ) ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$url = esc_url_raw( $url, [ 'http', 'https' ] );

		// Requests go through wp_safe_remote_get, which rejects private
		// network targets; here only the shape is checked.
		return '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Whether another host publishes the feed.
	 *
	 * @return bool
	 */
	public static function is_external(): bool {
		return 'external' === self::get( 'mode' );
	}

	/**
	 * The host's feed address in external mode ('' otherwise).
	 *
	 * @return string
	 */
	public static function source_feed_url(): string {
		return self::is_external() ? (string) self::get( 'feed_url' ) : '';
	}

	/**
	 * The feed address listeners and directories should use: the host's
	 * feed in external mode, this site's feed otherwise.
	 *
	 * @return string
	 */
	public static function public_feed_url(): string {
		$source = self::source_feed_url();

		return '' !== $source ? $source : Feed::url();
	}

	/**
	 * Where the local feed permanently redirects to, if anywhere.
	 *
	 * Only in external mode with redirects enabled and a host feed set.
	 * The "new feed URL" setting (moving away from self-hosting) is served
	 * as <itunes:new-feed-url> instead, so it stays readable for apps that
	 * do not follow redirects.
	 *
	 * @return string
	 */
	public static function feed_redirect_target(): string {
		if ( ! self::is_external() || ! self::get( 'redirect' ) ) {
			return '';
		}

		$target = (string) self::get( 'feed_url' );

		// Never redirect the feed to itself.
		if ( '' === $target || self::is_own_feed( $target ) ) {
			return '';
		}

		return $target;
	}

	/**
	 * Whether a URL is one of this site's own podcast feed addresses (any
	 * scheme, with or without a trailing slash, the feed's sub-paths or the
	 * query form).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_own_feed( string $url ): bool {
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $home || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home ) {
			return false;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( isset( $query[ Feed::QUERY_VAR ] ) ) {
			return true;
		}

		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$feed = untrailingslashit( (string) wp_parse_url( Feed::url(), PHP_URL_PATH ) );

		return '' !== $feed && ( $path === $feed || 0 === strpos( $path . '/', $feed . '/' ) );
	}

	/**
	 * Display name of the configured host.
	 *
	 * @return string
	 */
	public static function provider_name(): string {
		if ( ! self::is_external() ) {
			return __( 'This website', 'elementor-podcast-manager' );
		}

		$provider = Providers::get( (string) self::get( 'provider' ) );

		return null !== $provider ? (string) $provider['name'] : __( 'Another podcast host', 'elementor-podcast-manager' );
	}

	/**
	 * Sync bookkeeping.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return wp_parse_args(
			is_array( $state ) ? $state : [],
			[
				'last_run'      => 0,
				'last_success'  => 0,
				'status'        => '',
				'message'       => '',
				'etag'          => '',
				'last_modified' => '',
				'feed_url'      => '',
				'created'       => 0,
				'updated'       => 0,
				'drafted'       => 0,
				'pending'       => false,
				'failures'      => 0,
				'items'         => 0,
				'feed_title'    => '',
			]
		);
	}

	/**
	 * Merge values into the sync bookkeeping.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return void
	 */
	public static function update_state( array $values ): void {
		update_option( self::STATE_OPTION, array_merge( self::state(), $values ), false );
	}

	/**
	 * Settings saved: reschedule, and forget conditional-GET validators
	 * when the feed address changes so the next sync reads it in full.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function on_settings_saved( $old_value, $value ): void {
		$old = is_array( $old_value ) ? $old_value : [];
		$new = is_array( $value ) ? $value : [];

		if ( ( $old['feed_url'] ?? '' ) !== ( $new['feed_url'] ?? '' ) ) {
			self::update_state(
				[
					'etag'          => '',
					'last_modified' => '',
				]
			);
		}

		self::reschedule();
		Feed::flush_cache();
	}

	/**
	 * First save of the option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public static function on_settings_added( $option, $value ): void {
		self::reschedule();
		Feed::flush_cache();
	}

	/**
	 * Whether automatic syncing should run.
	 *
	 * @return bool
	 */
	public static function sync_enabled(): bool {
		return self::is_external() && '' !== (string) self::get( 'feed_url' ) && (bool) self::get( 'sync' );
	}

	/**
	 * Make the cron schedule match the settings.
	 *
	 * @return void
	 */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( self::sync_enabled() ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, (string) self::get( 'interval' ), self::CRON_HOOK );
		}
	}

	/**
	 * Repair a missing schedule (e.g. after a cron table reset).
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		if ( self::sync_enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, (string) self::get( 'interval' ), self::CRON_HOOK );
		}
	}

	/**
	 * Remove every scheduled event of the plugin (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( ImportJob::CRON_HOOK );
		wp_clear_scheduled_hook( Feed::PING_HOOK );
		wp_clear_scheduled_hook( Feed::PING_FILTER );
	}

	/**
	 * Cron entry point.
	 *
	 * @return void
	 */
	public static function run_scheduled_sync(): void {
		if ( ! self::sync_enabled() ) {
			return;
		}

		// Back off after repeated failures: 1, 2, 4 … up to 24 hours.
		$state    = self::state();
		$failures = (int) $state['failures'];
		if ( $failures >= 3 ) {
			$wait = min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** min( 5, $failures - 3 ) ) );
			if ( time() - (int) $state['last_run'] < $wait ) {
				return;
			}
		}

		self::sync();
	}

	/**
	 * Fetch a feed.
	 *
	 * Uses wp_safe_remote_get (no requests to private networks), a bounded
	 * response size and conditional-GET validators when given.
	 *
	 * @param string               $url        Feed URL.
	 * @param array<string, string> $validators Optional etag / last_modified.
	 * @return array{status: int, body: string, etag: string, last_modified: string, final_url: string, permanent: bool}|\WP_Error
	 */
	public static function fetch( string $url, array $validators = [] ) {
		$url = self::sanitize_feed_url( $url );

		if ( '' === $url ) {
			return new \WP_Error( 'epm_feed_url', __( 'Enter the RSS feed address (it starts with https://).', 'elementor-podcast-manager' ) );
		}

		$headers = [
			'Accept' => 'application/rss+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5',
		];
		if ( ! empty( $validators['etag'] ) ) {
			$headers['If-None-Match'] = (string) $validators['etag'];
		}
		if ( ! empty( $validators['last_modified'] ) ) {
			$headers['If-Modified-Since'] = (string) $validators['last_modified'];
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 30,
				'redirection'         => 5,
				'headers'             => $headers,
				'user-agent'          => 'ElementorPodcastManager/' . EPM_VERSION . '; ' . home_url( '/' ),
				'limit_response_size' => (int) apply_filters( 'epm_feed_max_bytes', 50 * MB_IN_BYTES ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'epm_feed_unreachable',
				sprintf(
					/* translators: %s: error message from the HTTP client */
					__( 'The feed could not be loaded: %s', 'elementor-podcast-manager' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// 202 and friends are not a feed (seen: bot-protection pages).
		if ( 304 !== $status && 200 !== $status ) {
			$body = (string) wp_remote_retrieve_body( $response );
			if ( preg_match( '/just a moment|cf-chl|captcha|challenge-platform|sgcaptcha/i', substr( $body, 0, 20000 ) ) ) {
				return new \WP_Error( 'epm_feed_blocked', __( 'The server answered with a bot-protection page instead of the feed. Ask the site owner to allow podcast apps to read the feed, or use the feed address from your podcast host.', 'elementor-podcast-manager' ) );
			}

			return new \WP_Error(
				'epm_feed_http',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The feed address answered with HTTP status %d. Check the address in your host’s dashboard.', 'elementor-podcast-manager' ),
					$status
				)
			);
		}

		// Follow permanent moves (301/308 only) like podcast apps do.
		$final_url = $url;
		$permanent = false;
		$raw       = $response['http_response'] ?? null;
		if ( $raw instanceof \WP_HTTP_Requests_Response ) {
			$object = $raw->get_response_object();
			if ( ! empty( $object->history ) ) {
				$permanent = true;
				foreach ( $object->history as $hop ) {
					if ( ! in_array( (int) $hop->status_code, [ 301, 308 ], true ) ) {
						$permanent = false;
						break;
					}
				}
				$final_url = (string) $object->url;
			}
		}

		// Never adopt a move from https to http.
		if ( 0 === stripos( $url, 'https://' ) && 0 !== stripos( $final_url, 'https://' ) ) {
			$permanent = false;
		}

		return [
			'status'        => $status,
			'body'          => (string) wp_remote_retrieve_body( $response ),
			'etag'          => self::header( $response, 'etag' ),
			'last_modified' => self::header( $response, 'last-modified' ),
			'final_url'     => $final_url,
			'permanent'     => $permanent && $final_url !== $url,
		];
	}

	/**
	 * One response header as a string (the last value when repeated).
	 *
	 * @param array<string, mixed> $response HTTP response.
	 * @param string               $name     Header name.
	 * @return string
	 */
	private static function header( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = end( $value );
		}

		return trim( (string) $value );
	}

	/**
	 * Find and fetch a show's feed from what a user pasted.
	 *
	 * Accepts the RSS feed address itself, an Apple Podcasts show link
	 * (resolved through Apple's public lookup API) or a web page that
	 * advertises its feed with <link rel="alternate">, e.g. the show's
	 * page at the host or its website.
	 *
	 * @param string $input Pasted address.
	 * @return array{url: string, fetched: array<string, mixed>}|\WP_Error
	 */
	public static function locate( string $input ) {
		$url = self::sanitize_feed_url( $input );
		if ( '' === $url ) {
			return new \WP_Error( 'epm_feed_url', __( 'Enter the RSS feed address (it starts with https://).', 'elementor-podcast-manager' ) );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// Apple Podcasts show link: ask Apple for the feed address.
		if ( in_array( $host, [ 'podcasts.apple.com', 'itunes.apple.com' ], true ) && preg_match( '#/id(\d+)#', $url, $m ) ) {
			$lookup = wp_safe_remote_get( 'https://itunes.apple.com/lookup?entity=podcast&id=' . $m[1], [ 'timeout' => 15 ] );
			$data   = is_wp_error( $lookup ) ? null : json_decode( (string) wp_remote_retrieve_body( $lookup ), true );
			$feed   = is_array( $data ) ? (string) ( $data['results'][0]['feedUrl'] ?? '' ) : '';
			if ( '' === $feed ) {
				return new \WP_Error( 'epm_feed_apple', __( 'Apple Podcasts did not return a feed address for this show. Paste the RSS feed address from your host instead.', 'elementor-podcast-manager' ) );
			}
			$url = self::sanitize_feed_url( $feed );
		} elseif ( in_array( $host, [ 'open.spotify.com', 'spotify.link' ], true ) ) {
			return new \WP_Error( 'epm_feed_spotify', __( 'Spotify show links do not reveal the RSS feed. In Spotify for Creators, open Settings → Availability and copy the RSS feed link (it looks like https://anchor.fm/s/…/podcast/rss).', 'elementor-podcast-manager' ) );
		}

		$fetched = self::fetch( $url );
		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		// A web page: follow its feed link once.
		if ( self::looks_like_html( $fetched['body'] ) ) {
			$feed = self::discover_feed_link( $fetched['body'], $fetched['final_url'] );
			if ( '' === $feed ) {
				if ( preg_match( '/just a moment|cf-chl|captcha|challenge-platform|sgcaptcha/i', substr( $fetched['body'], 0, 20000 ) ) ) {
					return new \WP_Error( 'epm_feed_blocked', __( 'The server answered with a bot-protection page instead of the feed. Ask the site owner to allow podcast apps to read the feed, or use the feed address from your podcast host.', 'elementor-podcast-manager' ) );
				}
				return new \WP_Error( 'epm_feed_html', __( 'This address is a web page, not an RSS feed, and the page does not link to one. Copy the RSS feed address from your host’s dashboard.', 'elementor-podcast-manager' ) );
			}
			$url     = $feed;
			$fetched = self::fetch( $url );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
		}

		return [
			'url'     => $fetched['permanent'] ? self::sanitize_feed_url( $fetched['final_url'] ) : $url,
			'fetched' => $fetched,
		];
	}

	/**
	 * Whether a response body is an HTML page rather than XML.
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	private static function looks_like_html( string $body ): bool {
		$head = strtolower( ltrim( substr( $body, 0, 2048 ) ) );

		return 0 === strpos( $head, '<!doctype html' ) || 0 === strpos( $head, '<html' ) || ( false !== strpos( $head, '<head' ) && false === strpos( $head, '<rss' ) );
	}

	/**
	 * RSS feed advertised by an HTML page, preferring podcast feeds.
	 *
	 * @param string $html Page.
	 * @param string $base Page URL (for relative links).
	 * @return string
	 */
	private static function discover_feed_link( string $html, string $base ): string {
		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $tags ) ) {
			return '';
		}

		// A blog feed (-1) is still taken when it is the only one; comment
		// feeds (-5) never are.
		$best  = '';
		$score = -2;
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '#\brel=["\']?alternate#i', $tag ) || ! preg_match( '#\btype=["\']?application/rss\+xml#i', $tag ) ) {
				continue;
			}
			if ( ! preg_match( '#\bhref=["\']([^"\']+)["\']#i', $tag, $href ) ) {
				continue;
			}
			$href = html_entity_decode( $href[1], ENT_QUOTES, 'UTF-8' );
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
			} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
				$href = \WP_Http::make_absolute_url( $href, $base );
			}

			// Score what names the feed (its address, then its title), never
			// the type attribute: every candidate is application/rss+xml. The
			// address counts more: on a site called "… Podcast" the blog
			// feed's title names the podcast too.
			$title = preg_match( '#\btitle=["\']([^"\']*)["\']#i', $tag, $label ) ? html_entity_decode( $label[1], ENT_QUOTES, 'UTF-8' ) : '';
			$named = (bool) preg_match( '#podcast|itunes|feed\.xml|\.rss(?:$|\?)|/rss/?(?:$|\?)#i', $href );

			$points = $named ? 3 : 0;
			if ( preg_match( '#podcast|itunes#i', $title ) ) {
				$points += 2;
			}
			if ( preg_match( '#comments#i', $href . ' ' . $title ) ) {
				$points -= 5;
			}
			// WordPress's blog feed (/feed/) comes first on every WordPress site.
			if ( ! $named && preg_match( '#^/feed/?$#', (string) wp_parse_url( $href, PHP_URL_PATH ) ) ) {
				--$points;
			}
			if ( $points > $score ) {
				$score = $points;
				$best  = $href;
			}
		}

		return self::sanitize_feed_url( $best );
	}

	/**
	 * Sync episodes from the host's feed.
	 *
	 * New episodes are created, changed ones updated (fields edited on this
	 * site are kept), and — when the "missing" policy says so — episodes the
	 * host removed are moved back to drafts. At most a bounded number of new
	 * episodes is created per run; the rest follows in a quick second run.
	 *
	 * @param bool $force Ignore conditional-GET validators (manual "Sync now").
	 * @return array{status: string, message: string, created: int, updated: int, drafted: int}
	 */
	public static function sync( bool $force = false ): array {
		$settings = self::all();
		$url      = (string) $settings['feed_url'];
		$state    = self::state();
		$now      = time();

		$result = [
			'status'  => 'error',
			'message' => '',
			'created' => 0,
			'updated' => 0,
			'drafted' => 0,
		];

		if ( '' === $url ) {
			$result['message'] = __( 'No host feed address is set.', 'elementor-podcast-manager' );
			return $result;
		}

		if ( ! ImportJob::acquire_lock() ) {
			$result['status']  = 'busy';
			$result['message'] = __( 'An import is running. The sync will run after it finishes.', 'elementor-podcast-manager' );
			return $result;
		}

		try {
			$validators = ( $force || $state['feed_url'] !== $url ) ? [] : [
				'etag'          => (string) $state['etag'],
				'last_modified' => (string) $state['last_modified'],
			];

			$fetched = self::fetch( $url, $validators );

			if ( is_wp_error( $fetched ) ) {
				$result['message'] = $fetched->get_error_message();
				self::update_state(
					[
						'last_run' => $now,
						'status'   => 'error',
						'message'  => $result['message'],
						'failures' => (int) $state['failures'] + 1,
					]
				);
				return $result;
			}

			if ( 304 === $fetched['status'] ) {
				$result['status']  = 'ok';
				$result['message'] = __( 'No new episodes.', 'elementor-podcast-manager' );
				self::update_state(
					[
						'last_run'     => $now,
						'last_success' => $now,
						'status'       => 'ok',
						'message'      => $result['message'],
						'created'      => 0,
						'updated'      => 0,
						'drafted'      => 0,
						'pending'      => false,
						'failures'     => 0,
					]
				);
				return $result;
			}

			$parsed = ( new FeedParser() )->parse( $fetched['body'] );
			if ( is_wp_error( $parsed ) ) {
				$result['message'] = $parsed->get_error_message();
				self::update_state(
					[
						'last_run' => $now,
						'status'   => 'error',
						'message'  => $result['message'],
						'failures' => (int) $state['failures'] + 1,
					]
				);
				return $result;
			}

			// A broken or truncated response must never change the site.
			$count    = count( $parsed['items'] );
			$previous = (int) ( $state['items'] ?? 0 );
			if ( 0 === $count || ( $previous >= 10 && $count < $previous / 2 ) ) {
				$result['message'] = 0 === $count
					? __( 'The host’s feed lists no episodes right now, so nothing was changed.', 'elementor-podcast-manager' )
					: sprintf(
						/* translators: 1: episodes in the feed now, 2: episodes in the feed before */
						__( 'The host’s feed suddenly lists %1$d episodes instead of %2$d, so nothing was changed. If you removed episodes on purpose, run “Sync now”.', 'elementor-podcast-manager' ),
						$count,
						$previous
					);
				if ( ! $force || 0 === $count ) {
					self::update_state(
						[
							'last_run' => $now,
							'status'   => 'error',
							'message'  => $result['message'],
							'failures' => (int) $state['failures'] + 1,
						]
					);
					return $result;
				}
			}

			$importer = new Importer(
				[
					'feed_url' => $url,
					'status'   => (string) $settings['new_status'],
				]
			);

			[ $unique ] = Importer::dedupe( $parsed['items'] );
			$parsed['items'] = $unique;

			$importer->prime();

			$limit   = max( 1, (int) apply_filters( 'epm_sync_batch_limit', 25 ) );
			$pending = false;
			$guids   = [];

			// Oldest first, so a capped run imports in publishing order.
			$items     = array_reverse( $parsed['items'] );
			$refreshed = time();
			foreach ( $items as $item ) {
				$guids[ (string) $item['guid'] ] = true;

				if ( $result['created'] >= $limit && ! $importer->exists( (string) $item['guid'] ) ) {
					$pending = true;
					continue;
				}

				// A long run keeps its lock (new episodes fetch chapters and
				// transcripts); renewed once a minute at most.
				if ( time() - $refreshed >= MINUTE_IN_SECONDS ) {
					ImportJob::refresh_lock();
					$refreshed = time();
				}
				$outcome = $importer->import_item( $item );
				if ( 'created' === $outcome['action'] ) {
					++$result['created'];
				} elseif ( 'updated' === $outcome['action'] ) {
					++$result['updated'];
				}
			}

			if ( 'draft' === $settings['missing'] && ! empty( $items ) ) {
				$result['drafted'] = $importer->draft_missing( $guids, self::oldest_date( $parsed['items'] ) );
			}

			// The host announced a new address: follow it, like apps do.
			$next_url = '';
			if ( '' !== (string) $parsed['channel']['new_feed_url'] ) {
				$next_url = self::sanitize_feed_url( (string) $parsed['channel']['new_feed_url'] );
			} elseif ( $fetched['permanent'] ) {
				$next_url = self::sanitize_feed_url( $fetched['final_url'] );
			}
			$own_feed   = '' !== $next_url && self::is_own_feed( $next_url );
			$moved_here = $own_feed && 'external' === $settings['mode'];
			if ( $moved_here ) {
				// The host sends apps to this website's feed (any scheme): the
				// show moved here. Redirecting back to the host would loop.
				$settings['mode'] = 'self';
				update_option( self::OPTION, $settings );
			} elseif ( ! $own_feed && '' !== $next_url && $next_url !== $url && ( 0 !== stripos( $url, 'https://' ) || 0 === stripos( $next_url, 'https://' ) ) ) {
				// Never adopt a move from https to http (fetch() has the same rule).
				$settings['feed_url'] = $next_url;
				update_option( self::OPTION, $settings );
			}

			$result['status']  = 'ok';
			$result['message'] = self::summary( $result );
			if ( $moved_here ) {
				$result['message'] .= ' ' . __( 'Your host now sends podcast apps to this website’s feed, so hosting switched to “This website”: the feed here is no longer redirected and syncing stopped.', 'elementor-podcast-manager' );
			}

			self::update_state(
				[
					'last_run'      => $now,
					'last_success'  => $now,
					'status'        => 'ok',
					'message'       => $result['message'],
					'etag'          => $pending ? '' : $fetched['etag'],
					'last_modified' => $pending ? '' : $fetched['last_modified'],
					'feed_url'      => $url,
					'created'       => $result['created'],
					'updated'       => $result['updated'],
					'drafted'       => $result['drafted'],
					'pending'       => $pending,
					'feed_title'    => (string) $parsed['channel']['title'],
					'failures'      => 0,
					'items'         => count( $parsed['items'] ),
				]
			);

			if ( $pending ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
			}

			if ( $result['created'] || $result['updated'] || $result['drafted'] ) {
				Feed::flush_cache();
			}

			return $result;
		} finally {
			ImportJob::release_lock();
		}
	}

	/**
	 * Oldest publish date among feed items (0 when unknown).
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return int
	 */
	private static function oldest_date( array $items ): int {
		$oldest = 0;
		foreach ( $items as $item ) {
			$date = (int) ( $item['pub_date'] ?? 0 );
			if ( $date > 0 && ( 0 === $oldest || $date < $oldest ) ) {
				$oldest = $date;
			}
		}

		return $oldest;
	}

	/**
	 * Human summary of a sync.
	 *
	 * @param array<string, int|string> $result Result.
	 * @return string
	 */
	public static function summary( array $result ): string {
		$parts = [];

		if ( (int) $result['created'] > 0 ) {
			/* translators: %s: number of episodes */
			$parts[] = sprintf( _n( '%s new episode', '%s new episodes', (int) $result['created'], 'elementor-podcast-manager' ), number_format_i18n( (int) $result['created'] ) );
		}
		if ( (int) $result['updated'] > 0 ) {
			/* translators: %s: number of episodes */
			$parts[] = sprintf( _n( '%s episode updated', '%s episodes updated', (int) $result['updated'], 'elementor-podcast-manager' ), number_format_i18n( (int) $result['updated'] ) );
		}
		if ( (int) $result['drafted'] > 0 ) {
			/* translators: %s: number of episodes */
			$parts[] = sprintf( _n( '%s episode moved to drafts', '%s episodes moved to drafts', (int) $result['drafted'], 'elementor-podcast-manager' ), number_format_i18n( (int) $result['drafted'] ) );
		}

		return empty( $parts ) ? __( 'Up to date.', 'elementor-podcast-manager' ) : implode( ', ', $parts ) . '.';
	}
}
