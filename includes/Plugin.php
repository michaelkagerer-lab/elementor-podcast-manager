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
		( new EpisodeTemplate() )->init();

		// One-time upgrade tasks (rewrite rules) after a plugin update.
		add_action( 'init', [ $this, 'maybe_upgrade' ], 99 );

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

		// Translations load on init (WordPress 6.7+ flags earlier loading).
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( EPM_TEXT_DOMAIN, false, dirname( plugin_basename( EPM_FILE ) ) . '/languages' );
			},
			0
		);
	}

	/**
	 * Run one-time upgrade tasks when the stored version differs.
	 *
	 * Rewrite rules are flushed so installs updated in place (activation
	 * hooks do not run on update) pick up the corrected rule order that
	 * lets /podcast/feed/ serve the podcast feed.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$stored = (string) get_option( 'epm_version', '' );

		if ( EPM_VERSION === $stored ) {
			return;
		}

		flush_rewrite_rules( false );
		Feed::flush_cache();

		// 1.2.0: numeric durations for sorting.
		$ids = get_posts(
			[
				'post_type'      => EpisodePostType::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( $ids as $id ) {
			Episodes::sync_duration_seconds( (int) $id );
		}

		// Widget CSS is generated from control selectors and cached by
		// Elementor per page; regenerate it so updated selectors apply.
		if ( $this->has_elementor() && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		update_option( 'epm_version', EPM_VERSION );
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
	 * Exposes episode metadata so Elementor Pro dynamic tags, Gutenberg and
	 * headless consumers can use it without PHP. Each key carries its own
	 * sanitizer: WordPress applies it on EVERY update_post_meta() call, so a
	 * generic sanitize_text_field would strip the HTML from show notes and
	 * transcripts and the line breaks from bios on each save.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$int_or_empty = static function ( $value ) {
			return ( '' === $value || null === $value ) ? '' : absint( $value );
		};

		$fields = [
			// key => [ REST type, sanitize callback, editable via REST ].
			'audio_id'          => [ 'integer', 'absint', true ],
			'artwork_id'        => [ 'integer', 'absint', true ],
			'guest_image_id'    => [ 'integer', 'absint', true ],
			'audio_size'        => [ 'integer', 'absint', false ],
			'duration_seconds'  => [ 'integer', 'absint', false ],
			'episode_number'    => [ 'integer', $int_or_empty, true ],
			'season_number'     => [ 'integer', $int_or_empty, true ],
			'duration'          => [ 'string', 'sanitize_text_field', true ],
			'episode_type'      => [ 'string', 'sanitize_key', true ],
			'explicit'          => [ 'string', 'sanitize_key', true ],
			'short_description' => [ 'string', 'sanitize_textarea_field', true ],
			'show_notes'        => [ 'string', 'wp_kses_post', true ],
			'transcript'        => [ 'string', 'wp_kses_post', true ],
			'canonical_url'     => [ 'string', 'esc_url_raw', true ],
			'video_url'         => [ 'string', 'esc_url_raw', true ],
			'youtube_url'       => [ 'string', 'esc_url_raw', true ],
			'guest_name'        => [ 'string', 'sanitize_text_field', true ],
			'guest_role'        => [ 'string', 'sanitize_text_field', true ],
			'guest_company'     => [ 'string', 'sanitize_text_field', true ],
			'guest_bio'         => [ 'string', 'sanitize_textarea_field', true ],
			// Immutable identity: readable, never writable through REST.
			'guid'              => [ 'string', 'sanitize_text_field', false ],
		];

		foreach ( $fields as $key => [ $type, $sanitize, $editable ] ) {
			register_post_meta(
				EpisodePostType::CPT,
				Episodes::META_PREFIX . $key,
				[
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $editable ? [ $this, 'meta_auth' ] : '__return_false',
					'sanitize_callback' => $sanitize,
				]
			);
		}

		// Structured values keep their array shape in REST.
		$structures = [
			'chapters'      => [ 'time', 'title', 'url' ],
			'platform_urls' => [ 'service', 'label', 'url' ],
		];

		foreach ( $structures as $key => $properties ) {
			register_post_meta(
				EpisodePostType::CPT,
				Episodes::META_PREFIX . $key,
				[
					'type'          => 'array',
					'single'        => true,
					'show_in_rest'  => [
						'schema' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => array_fill_keys( $properties, [ 'type' => 'string' ] ),
							],
						],
					],
					'auth_callback' => [ $this, 'meta_auth' ],
				]
			);
		}

		// Password-protected episodes keep their metadata private in REST.
		add_filter( 'rest_prepare_' . EpisodePostType::CPT, [ $this, 'protect_rest_meta' ], 10, 2 );
	}

	/**
	 * Meta write authorization: whoever may edit the episode.
	 *
	 * @param bool   $allowed  Whether allowed.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public function meta_auth( $allowed, $meta_key = '', $post_id = 0 ): bool {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Hide episode meta in REST responses for password-protected episodes
	 * the requester has not unlocked and cannot edit.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @return \WP_REST_Response
	 */
	public function protect_rest_meta( $response, $post ) {
		if ( $response instanceof \WP_REST_Response && $post instanceof \WP_Post
			&& post_password_required( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			$data = $response->get_data();
			if ( isset( $data['meta'] ) ) {
				$data['meta'] = [];
				$response->set_data( $data );
			}
		}

		return $response;
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
