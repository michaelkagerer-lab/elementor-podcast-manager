<?php
/**
 * Frontend asset management (Layer 2).
 *
 * CSS/JS load only when podcast components are actually used on the page.
 * Detection: shortcodes in content, Elementor widget render flags.
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
	 * Mark that a player was rendered (called by the Renderer).
	 *
	 * @return void
	 */
	public static function mark_player_used(): void {
		self::$player_used = true;
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
		// Late enqueue: widgets/shortcodes render after wp_enqueue_scripts,
		// so print assets in the footer when they were actually used.
		add_action( 'wp_footer', [ $this, 'maybe_enqueue_late' ], 5 );
		add_action( 'wp_footer', [ $this, 'maybe_output_sticky' ], 25 );
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
		if ( ! self::$sticky_requested ) {
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
