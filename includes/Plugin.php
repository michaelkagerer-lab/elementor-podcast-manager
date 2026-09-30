<?php
/**
 * Main plugin class. Wires every module together.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Podcast settings handler.
	 *
	 * @var PodcastSettings
	 */
	public PodcastSettings $settings;

	/**
	 * Design (global styles) handler.
	 *
	 * @var DesignSettings
	 */
	public DesignSettings $design;

	/**
	 * Preset registry.
	 *
	 * @var Presets
	 */
	public Presets $presets;

	/**
	 * Episode repository / query helper.
	 *
	 * @var Episodes
	 */
	public Episodes $episodes;

	/**
	 * RSS feed handler.
	 *
	 * @var Feed
	 */
	public Feed $feed;

	/**
	 * Frontend renderer (shared markup primitives).
	 *
	 * @var Renderer
	 */
	public Renderer $renderer;

	/**
	 * Asset manager.
	 *
	 * @var Assets
	 */
	public Assets $assets;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->settings = new PodcastSettings();
		$this->design   = new DesignSettings();
		$this->presets  = new Presets();
		$this->episodes = new Episodes();
		$this->feed     = new Feed();
		$this->renderer = new Renderer();
		$this->assets   = new Assets();

		// Layer 1: podcast engine (Elementor-independent).
		// The CPT registers normally on init (see Episodes::init()); activation
		// registers it explicitly once for rewrite flushing.
		$this->settings->init();
		$this->design->init();
		$this->episodes->init();
		$this->feed->init();
		$this->assets->init();
		$this->register_meta();
		new Shortcodes();

		// Canonical URL behavior: an explicit per-episode canonical URL wins.
		add_filter( 'get_canonical_url', [ $this, 'filter_canonical_url' ], 10, 2 );

		// Admin UI.
		if ( is_admin() ) {
			$admin = new Admin();
			$admin->init();
		}

		// Layer 3: Elementor presentation (only when Elementor is active).
		add_action( 'elementor/init', [ $this, 'init_elementor' ] );

		// Admin notice when Elementor is missing (presentation layer only).
		add_action( 'admin_notices', [ $this, 'elementor_missing_notice' ] );

		load_plugin_textdomain( EPM_TEXT_DOMAIN, false, dirname( plugin_basename( EPM_FILE ) ) . '/languages' );
	}

	/**
	 * Canonical URL behavior for episodes.
	 *
	 * When an episode has an explicit canonical URL set, it is used as the
	 * canonical URL (e.g. for cross-posted or migrated episodes). Otherwise
	 * WordPress core behavior applies unchanged.
	 *
	 * @param string   $canonical_url Core canonical URL.
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public function filter_canonical_url( string $canonical_url, \WP_Post $post ): string {
		if ( EpisodePostType::CPT !== $post->post_type ) {
			return $canonical_url;
		}

		$custom = (string) get_post_meta( $post->ID, Episodes::META_PREFIX . 'canonical_url', true );

		return '' !== $custom ? $custom : $canonical_url;
	}

	/**
	 * Register episode metadata for the REST API / dynamic integrations.
	 *
	 * Exposes read-only episode metadata so Elementor Pro dynamic tags,
	 * Gutenberg and headless consumers can use it without PHP.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$public_meta = [
			'audio_id'          => 'integer',
			'artwork_id'        => 'integer',
			'duration'          => 'string',
			'audio_size'        => 'integer',
			'episode_number'    => 'integer',
			'season_number'     => 'integer',
			'episode_type'      => 'string',
			'explicit'          => 'string',
			'short_description' => 'string',
			'show_notes'        => 'string',
			'transcript'        => 'string',
			'canonical_url'     => 'string',
			'video_url'         => 'string',
			'youtube_url'       => 'string',
			'guest_name'        => 'string',
			'guest_role'        => 'string',
			'guest_company'     => 'string',
			'guest_bio'         => 'string',
			'guid'              => 'string',
		];

		foreach ( $public_meta as $key => $type ) {
			register_post_meta(
				EpisodePostType::CPT,
				Episodes::META_PREFIX . $key,
				[
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => '__return_true',
					'sanitize_callback' => 'sanitize_text_field',
				]
			);
		}

		// Structured values keep their array shape in REST.
		foreach ( [ 'chapters', 'platform_urls' ] as $key ) {
			register_post_meta(
				EpisodePostType::CPT,
				Episodes::META_PREFIX . $key,
				[
					'type'          => 'array',
					'single'        => true,
					'show_in_rest'  => [
						'schema' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
					'auth_callback' => '__return_true',
				]
			);
		}
	}

	/**
	 * Boot the Elementor integration.
	 *
	 * @return void
	 */
	public function init_elementor(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		$integration = new Elementor\Integration();
		$integration->init();
	}

	/**
	 * Whether Elementor is currently active.
	 *
	 * @return bool
	 */
	public function has_elementor(): bool {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * Admin notice when Elementor is not available.
	 * Podcast management and RSS keep working; only presentation is affected.
	 *
	 * @return void
	 */
	public function elementor_missing_notice(): void {
		if ( $this->has_elementor() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, 'epm' ) === false && strpos( (string) $screen->id, 'podcast' ) === false ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Elementor Podcast Manager: Elementor is not active. Podcast management and the RSS feed keep working; the Elementor widgets are unavailable until Elementor is installed and activated.', 'elementor-podcast-manager' )
		);
	}
}
