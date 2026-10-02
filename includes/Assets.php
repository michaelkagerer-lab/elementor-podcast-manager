<?php
/**
 * Frontend asset management (Layer 2).
 *
 * CSS/JS load only when podcast components are actually used on the page:
 *
 * - early (wp_enqueue_scripts, so the stylesheet is in <head>): the
 *   automatic episode page, and podcast shortcodes in the content of the
 *   page that would show something (enqueue_early());
 * - Elementor pages: the widgets' style/script dependencies (Elementor
 *   prints them in <head> from its page-asset list);
 * - everything else when it renders (widgets, shortcodes in other places,
 *   the_content of other posts), printed in the footer (maybe_enqueue_late()).
 *
 * The player script is dropped again before the footer scripts print when
 * nothing on the page used it (dequeue_unused()), e.g. a "current episode"
 * widget on a page that is not an episode.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	/**
	 * Whether any player markup was rendered on this request.
	 *
	 * @var bool
	 */
	private static bool $player_used = false;
	private static bool $style_used = false;

	/**
	 * Whether the sticky player shell is requested.
	 *
	 * @var bool
	 */
	private static bool $sticky_requested = false;

	/**
	 * Whether this request renders the Elementor editor's preview.
	 *
	 * @var bool
	 */
	private static bool $elementor_preview = false;

	/**
	 * Mark that a player was rendered (called by the Renderer).
	 *
	 * @return void
	 */
	public static function mark_player_used(): void {
		self::$player_used = true;
	}

	/**
	 * Whether podcast components (styles or the player) were used on this
	 * request. Always true in the Elementor editor preview, where widgets
	 * can be added at any time.
	 *
	 * @return bool
	 */
	public static function is_used(): bool {
		return self::$player_used || self::$style_used || self::$elementor_preview;
	}

	/**
	 * Forget what this request used (for tests, and for code that renders
	 * several documents in one request).
	 *
	 * @return void
	 */
	public static function reset_usage(): void {
		self::$player_used      = false;
		self::$style_used       = false;
		self::$sticky_requested = false;
	}

	/**
	 * Shortcodes and what they need: 'script' (the player engine) or
	 * 'style'.
	 *
	 * @return array<string, string>
	 */
	private static function shortcode_needs(): array {
		return [
			'podcast_player'     => 'script',
			'podcast_latest'     => 'script',
			'podcast_episodes'   => 'script',
			'podcast_chapters'   => 'script',
			'podcast_video'      => 'script',
			'podcast_guest'      => 'style',
			'podcast_show_notes' => 'style',
			'podcast_transcript' => 'style',
			'podcast_subscribe'  => 'style',
			'podcast_latest_cta' => 'style',
		];
	}

	/**
	 * Enqueue in wp_enqueue_scripts, so the stylesheet (and the design
	 * tokens) print in <head>, for the automatic episode page and for
	 * podcast shortcodes in the content of the page. A shortcode for an
	 * episode counts when its episode resolves (a "current episode"
	 * shortcode on a page that is not an episode shows nothing).
	 * Everything else is enqueued when it renders (footer).
	 *
	 * @return void
	 */
	public function enqueue_early(): void {
		if ( is_admin() || is_feed() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( EpisodePostType::CPT === $post->post_type && EpisodeTemplate::will_render( $post ) ) {
			self::enqueue();
			return;
		}

		$content = (string) $post->post_content;
		if ( false === strpos( $content, '[podcast_' ) || post_password_required( $post ) ) {
			return;
		}

		$needs = self::shortcode_needs();
		if ( ! preg_match_all( '/' . get_shortcode_regex( array_keys( $needs ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		$want = '';
		foreach ( $matches as $match ) {
			// [[escaped]] shortcodes are not rendered.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}
			$tag  = $match[2];
			$atts = shortcode_parse_atts( $match[3] );
			if ( ! self::shortcode_shows( $tag, is_array( $atts ) ? $atts : [] ) ) {
				continue;
			}
			$want = 'script' === $needs[ $tag ] ? 'script' : ( '' === $want ? 'style' : $want );
			if ( 'script' === $want ) {
				break;
			}
		}

		if ( 'script' === $want ) {
			self::enqueue();
		} elseif ( 'style' === $want ) {
			self::enqueue_style();
		}
	}

	/**
	 * Whether a podcast shortcode would show something on this page (see
	 * enqueue_early(); the shortcode itself decides when it renders).
	 *
	 * @param string               $tag  Shortcode.
	 * @param array<string, mixed> $atts Attributes.
	 * @return bool
	 */
	private static function shortcode_shows( string $tag, array $atts ): bool {
		switch ( $tag ) {
			case 'podcast_episodes':
			case 'podcast_subscribe':
				return true;
			case 'podcast_latest_cta':
				return ! empty( epm()->settings->get( 'latest_cta_enabled' ) );
			case 'podcast_latest':
				return null !== epm()->episodes->get_latest( true );
			default:
				$id     = absint( $atts['id'] ?? 0 );
				$source = $id > 0 ? 'specific' : sanitize_key( (string) ( $atts['source'] ?? 'current' ) );
				return null !== epm()->renderer->resolve_episode( $source, $id );
		}
	}

	/**
	 * Drop the player script when nothing on the page used it (Elementor
	 * enqueues widget scripts for every widget on a page, also for one
	 * that shows nothing). Runs before the footer scripts print. Not in
	 * the Elementor editor preview, where widgets come and go.
	 *
	 * @return void
	 */
	public function dequeue_unused(): void {
		if ( self::$player_used || self::$elementor_preview || is_admin() ) {
			return;
		}

		/**
		 * Whether to drop the player script on pages that showed no player,
		 * list or chapters. Turn it off when code adds podcast markup to the
		 * page later (it then needs the script).
		 *
		 * @param bool $drop Default true.
		 */
		if ( ! apply_filters( 'epm_dequeue_unused_player', true ) ) {
			return;
		}

		if ( wp_script_is( 'epm-player', 'enqueued' ) && ! wp_script_is( 'epm-player', 'done' ) ) {
			wp_dequeue_script( 'epm-player' );
		}
	}

	/**
	 * Request the sticky player shell in the footer.
	 *
	 * @return void
	 */
	public static function request_sticky_player(): void {
		self::$sticky_requested = true;
		self::$player_used      = true;
	}

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Register early (init) so Elementor widget dependencies
		// (get_script_depends / get_style_depends) can enqueue by handle
		// whenever a widget renders, on frontend or in the editor.
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_early' ] );
		add_action( 'wp_print_footer_scripts', [ $this, 'dequeue_unused' ], 1 );
		// Late enqueue: widgets/shortcodes render after wp_enqueue_scripts,
		// so print assets in the footer when they were actually used.
		add_action( 'wp_footer', [ $this, 'maybe_enqueue_late' ], 5 );
		add_action( 'wp_footer', [ $this, 'maybe_output_sticky' ], 25 );
		add_action( 'elementor/preview/init', [ $this, 'mark_elementor_preview' ] );
	}

	/**
	 * The editor's preview re-renders widgets without loading the page
	 * again, so its footer is printed once: the sticky shell is always
	 * there (hidden) for a player whose sticky option is turned on while
	 * editing. Whether a press opens it is up to the pressed player (see
	 * data-epm-sticky-player), as on the site.
	 *
	 * @return void
	 */
	public function mark_elementor_preview(): void {
		self::$elementor_preview = true;
	}

	/**
	 * Register (not enqueue) assets.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_style(
			'epm-frontend',
			EPM_URL . 'assets/css/epm-frontend.css',
			[],
			EPM_VERSION
		);

		wp_register_script(
			'epm-player',
			EPM_URL . 'assets/js/epm-player.js',
			[],
			EPM_VERSION,
			true
		);

		// Embed bridge: loads only inside the episode embed (see Embed).
		wp_register_script(
			'epm-embed',
			EPM_URL . 'assets/js/epm-embed.js',
			[],
			EPM_VERSION,
			true
		);

		// Localized UI strings for the player engine (it has no strings of
		// its own). Labels that belong to markup are rendered with it.
		wp_localize_script(
			'epm-player',
			'epmPlayer',
			[
				// Shown as the "artist" in lock-screen / OS media controls.
				'podcastTitle' => (string) epm()->settings->get( 'title' ),
				// Resume each episode where the visitor stopped (browser storage).
				'resume'       => (bool) apply_filters( 'epm_player_resume', true ),
				'strings'      => [
					'play'         => __( 'Play', 'elementor-podcast-manager' ),
					'pause'        => __( 'Pause', 'elementor-podcast-manager' ),
					'playEpisode'  => __( 'Play episode', 'elementor-podcast-manager' ),
					'pauseEpisode' => __( 'Pause episode', 'elementor-podcast-manager' ),
					'playPause'    => __( 'Play or pause', 'elementor-podcast-manager' ),
					// Names of the card and row play buttons.
					/* translators: %s: episode title */
					'playTitle'    => __( 'Play %s', 'elementor-podcast-manager' ),
					/* translators: %s: episode title */
					'pauseTitle'   => __( 'Pause %s', 'elementor-podcast-manager' ),
					/* translators: %s: episode title */
					'retryTitle'   => __( 'Retry %s', 'elementor-podcast-manager' ),
					'audioError'   => __( 'This audio could not be loaded. Check your connection and try again.', 'elementor-podcast-manager' ),
					'retry'        => __( 'Retry', 'elementor-podcast-manager' ),
					// Short form for the one-line sticky bar title.
					'audioErrorShort' => __( 'This audio could not be loaded.', 'elementor-podcast-manager' ),
					/* translators: %s: playback speed, e.g. 1.5× */
					'speedChanged' => __( 'Playback speed: %s', 'elementor-podcast-manager' ),
					/* translators: 1: elapsed time, e.g. 1:05, 2: total duration, e.g. 42:10 */
					'seekValue'    => _x( '%1$s of %2$s', 'player position', 'elementor-podcast-manager' ),
					// Share menu feedback (announced to screen readers).
					'linkCopied'   => __( 'Link copied', 'elementor-podcast-manager' ),
					/* translators: %s: playback position, e.g. 12:34 */
					'linkAtCopied' => __( 'Link at %s copied', 'elementor-podcast-manager' ),
					'embedCopied'  => __( 'Embed code copied', 'elementor-podcast-manager' ),
					/* translators: %s: playback position, e.g. 12:34 */
					'startsAt'     => __( 'Starts at %s', 'elementor-podcast-manager' ),
					// Spoken value of the volume slider ("70%"). The engine
					// fills %s; %% is a literal percent sign.
					/* translators: %s: volume level in percent, e.g. 70 */
					'volumeValue'  => sprintf( __( '%s%%', 'elementor-podcast-manager' ), '%s' ),
				],
			]
		);
	}

	/**
	 * Enqueue frontend assets when needed. Called by widgets/shortcodes
	 * during render, plus a late content check as a safety net.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		self::$player_used = true;
		self::$style_used  = true;

		if ( ! wp_style_is( 'epm-frontend', 'registered' ) ) {
			return;
		}

		wp_enqueue_style( 'epm-frontend' );
		wp_enqueue_script( 'epm-player' );
	}

	/**
	 * Enqueue only the stylesheet (text-only widgets/shortcodes).
	 * Never pulls in the player script.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		self::$style_used = true;

		if ( ! wp_style_is( 'epm-frontend', 'registered' ) ) {
			return;
		}

		wp_enqueue_style( 'epm-frontend' );
	}

	/**
	 * Late enqueue in the footer for assets requested during content render.
	 *
	 * @return void
	 */
	public function maybe_enqueue_late(): void {
		if ( ! self::$player_used && ! self::$style_used ) {
			return;
		}

		if ( ( self::$player_used || self::$style_used ) && wp_style_is( 'epm-frontend', 'registered' ) && ! wp_style_is( 'epm-frontend', 'done' ) ) {
			wp_enqueue_style( 'epm-frontend' );
			wp_print_styles( 'epm-frontend' );
		}

		if ( self::$player_used && wp_script_is( 'epm-player', 'registered' ) && ! wp_script_is( 'epm-player', 'done' ) ) {
			wp_enqueue_script( 'epm-player' );
		}
	}

	/**
	 * Output the sticky player shell when requested.
	 *
	 * @return void
	 */
	public function maybe_output_sticky(): void {
		if ( ! self::$sticky_requested && ! self::$elementor_preview ) {
			return;
		}

		self::enqueue();
		$renderer = epm()->renderer;
		?>
		<div class="epm-sticky" data-epm-sticky role="region" aria-label="<?php echo esc_attr__( 'Audio player', 'elementor-podcast-manager' ); ?>" hidden>
			<div class="epm-sticky__artwork" data-epm-sticky-artwork></div>
			<div class="epm-sticky__info">
				<p class="epm-sticky__title" data-epm-sticky-title></p>
				<span class="epm-sticky__time"><span data-epm-current>0:00</span> / <span data-epm-total>0:00</span></span>
				<div class="epm-sticky__timeline" data-epm-timeline role="slider" tabindex="0"
					aria-label="<?php echo esc_attr__( 'Seek', 'elementor-podcast-manager' ); ?>"
					aria-valuemin="0" aria-valuemax="0" aria-valuenow="0">
					<div class="epm-sticky__track"><div class="epm-sticky__progress" data-epm-progress></div></div>
					<div class="epm-sticky__handle" data-epm-handle></div>
				</div>
			</div>
			<div class="epm-sticky__controls">
				<button type="button" class="epm-sticky__play" data-epm-play aria-label="<?php echo esc_attr__( 'Play', 'elementor-podcast-manager' ); ?>"
					data-label-play="<?php echo esc_attr__( 'Play', 'elementor-podcast-manager' ); ?>"
					data-label-pause="<?php echo esc_attr__( 'Pause', 'elementor-podcast-manager' ); ?>"
					data-label-retry="<?php echo esc_attr__( 'Retry', 'elementor-podcast-manager' ); ?>">
					<?php echo $renderer->play_toggle_icons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
				</button>
				<button type="button" class="epm-sticky__speed" data-epm-speed>
					<span class="epm-sr-only"><?php esc_html_e( 'Playback speed', 'elementor-podcast-manager' ); ?> </span><span data-epm-speed-value>1×</span>
				</button>
				<button type="button" class="epm-sticky__close" data-epm-sticky-close aria-label="<?php echo esc_attr__( 'Close player', 'elementor-podcast-manager' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
				</button>
			</div>
		</div>
		<?php
	}
}
