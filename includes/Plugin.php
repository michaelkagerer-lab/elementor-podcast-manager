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
		( new Hosting() )->init();
		( new ImportJob() )->init();
		Upgrade::init();
		Cli::register();
		( new StructuredData() )->init();
		Transcripts::init();
		$this->register_meta();
		new Shortcodes();
		( new EpisodeTemplate() )->init();
		( new Embed() )->init();

		// One-time upgrade tasks after a plugin update (cheap; per-episode
		// work is queued, see Upgrade).
		add_action( 'init', [ $this, 'maybe_upgrade' ], 99 );
		add_action( 'init', [ $this, 'resume_interrupted_import' ], 2 );

		// Canonical URL behavior: an explicit per-episode canonical URL wins.
		add_filter( 'get_canonical_url', [ $this, 'filter_canonical_url' ], 10, 2 );

		// Admin UI.
		if ( is_admin() ) {
			$admin = new Admin();
			$admin->init();
			( new AdminPages() )->init();
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
	 * Resume an import left active when the plugin was deactivated.
	 *
	 * @return void
	 */
	public function resume_interrupted_import(): void {
		$job = ImportJob::get();
		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) || ! empty( $job['cancelled'] ) ) {
			return;
		}
		if ( ! wp_next_scheduled( ImportJob::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, ImportJob::CRON_HOOK );
		}
	}

	/**
	 * Run one-time upgrade tasks when the stored version differs.
	 *
	 * This runs in the first request after an update, which may be any
	 * visitor's, so it does only cheap work: rewrite rules (installs
	 * updated in place never run the activation hook), the feed cache, the
	 * 1.3.0 import folder and Elementor's widget CSS. The new version is
	 * stored first, with a conditional write, so exactly one request does
	 * this and a request that dies does not leave the site upgrading on
	 * every request. Work on every episode is queued (Upgrade) and done in
	 * batches by WP-Cron, admin page loads or `wp podcast upgrade`.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$stored = (string) get_option( 'epm_version', '' );

		if ( EPM_VERSION === $stored ) {
			return;
		}

		if ( ! self::claim_version( $stored ) ) {
			// Another request is upgrading (or did already).
			return;
		}

		flush_rewrite_rules( false );
		Feed::flush_cache();
		// 1.3.0 kept the feed in one transient row.
		delete_transient( 'epm_feed_cache' );
		Feed::repair_build_time();

		// 1.3.0 kept the parsed feed of an import in uploads/epm-import/:
		// move a running import to the database, remove the rest.
		ImportJob::cleanup();

		// Widget CSS is generated from control selectors and cached by
		// Elementor per page; regenerate it so updated selectors apply.
		if ( $this->has_elementor() && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		// Durations in seconds (1.2.0) and duplicate GUID rows: per episode,
		// in batches.
		Upgrade::queue( $stored );
	}

	/**
	 * Store the running version in place of $stored, only if no other
	 * request did so first.
	 *
	 * @param string $stored Version read before ('' when none is stored).
	 * @return bool Whether this request stored it.
	 */
	private static function claim_version( string $stored ): bool {
		$claimed = OptionRow::replace( 'epm_version', $stored, EPM_VERSION );

		if ( ! $claimed && '' === $stored ) {
			$claimed = OptionRow::insert( 'epm_version', EPM_VERSION, true );
		}

		// Either way, the next get_option() reads the database (a cache
		// may have served an older value).
		OptionRow::forget( 'epm_version' );

		return $claimed;
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

		// GUIDs are kept byte-for-byte: sanitize_text_field() drops
		// %-escapes ("f%C3%BCr"), and the next import would then create the
		// episode again.
		$guid = static function ( $value ): string {
			return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_check_invalid_utf8( (string) $value ) ) );
		};

		$fields = [
			// key => [ REST type, sanitize callback, editable via REST ].
			'audio_id'          => [ 'integer', 'absint', true ],
			'artwork_id'        => [ 'integer', [ EpisodeMeta::class, 'sanitize_image_id' ], true ],
			'guest_image_id'    => [ 'integer', [ EpisodeMeta::class, 'sanitize_image_id' ], true ],
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
			// Transcript file for captions (WebVTT/SRT/JSON): an upload, or
			// a URL kept from an imported feed.
			'transcript_file_id' => [ 'integer', 'absint', true ],
			'transcript_url'    => [ 'string', 'esc_url_raw', true ],
			'transcript_type'   => [ 'string', 'sanitize_text_field', true ],
			'canonical_url'     => [ 'string', 'esc_url_raw', true ],
			'video_url'         => [ 'string', 'esc_url_raw', true ],
			'youtube_url'       => [ 'string', 'esc_url_raw', true ],
			// Audio and image hosted elsewhere (host, CDN, storage bucket).
			'audio_url'         => [ 'string', 'esc_url_raw', true ],
			'audio_type'        => [ 'string', 'sanitize_text_field', true ],
			'audio_length'      => [ 'integer', 'absint', true ],
			'artwork_url'       => [ 'string', 'esc_url_raw', true ],
			// Import bookkeeping: readable, written by the importer only.
			'source'            => [ 'string', 'sanitize_key', false ],
			'source_link'       => [ 'string', 'esc_url_raw', false ],
			'source_feed'       => [ 'string', [ Importer::class, 'source_feed_identifier' ], false ],
			'guest_name'        => [ 'string', 'sanitize_text_field', true ],
			'guest_role'        => [ 'string', 'sanitize_text_field', true ],
			'guest_company'     => [ 'string', 'sanitize_text_field', true ],
			'guest_bio'         => [ 'string', 'sanitize_textarea_field', true ],
			// Immutable identity: readable, never writable through REST.
			'guid'              => [ 'string', $guid, false ],
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
					'type'              => 'array',
					'single'            => true,
					'sanitize_callback' => 'chapters' === $key ? [ self::class, 'sanitize_rest_chapters' ] : [ self::class, 'sanitize_rest_platform_urls' ],
					'show_in_rest'      => [
						'schema' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => array_fill_keys( $properties, [ 'type' => 'string' ] ),
							],
						],
					],
					'auth_callback'     => [ $this, 'meta_auth' ],
				]
			);
		}

		// Password-protected episodes keep their metadata private in REST.
		add_filter( 'rest_prepare_' . EpisodePostType::CPT, [ $this, 'protect_rest_meta' ], 10, 3 );

		// Audio and transcript files set through REST must be readable media.
		add_filter( 'rest_pre_insert_' . EpisodePostType::CPT, [ $this, 'rest_check_attachment_meta' ], 10, 2 );
	}

	/**
	 * Sanitize structured episode chapters before REST storage.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int, array{time: string, title: string, url: string}>
	 */
	public static function sanitize_rest_chapters( $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? array_slice( $value, 0, 200 ) : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$time = sanitize_text_field( $row['time'] ?? '' );
			$title = sanitize_text_field( $row['title'] ?? '' );
			if ( '' === $time || '' === $title || ! preg_match( '/^(?:\d{1,2}:)?\d{1,2}:\d{2}(?:\.\d{1,3})?$/', $time ) ) {
				continue;
			}
			$out[] = [ 'time' => $time, 'title' => $title, 'url' => esc_url_raw( $row['url'] ?? '', [ 'http', 'https' ] ) ];
		}
		return $out;
	}

	/**
	 * Sanitize structured platform links before REST storage.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int, array{service: string, label: string, url: string}>
	 */
	public static function sanitize_rest_platform_urls( $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? array_slice( $value, 0, 20 ) : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = esc_url_raw( $row['url'] ?? '', [ 'http', 'https' ] );
			if ( '' === $url ) {
				continue;
			}
			$out[] = [ 'service' => sanitize_key( $row['service'] ?? 'custom' ), 'label' => sanitize_text_field( $row['label'] ?? '' ), 'url' => $url ];
		}
		return $out;
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
	 * REST: a newly set audio or transcript file must be an attachment of
	 * the right kind that the user may read (media attached to someone
	 * else's unpublished episode is not). Unchanged values pass, so
	 * re-saving keeps the stored file.
	 *
	 * The check lives here and not in the meta sanitizers: those also run
	 * for the editor's own saves and for imports from cron, where there is
	 * no user, and would silently clear stored files.
	 *
	 * @param \stdClass|\WP_Error $prepared Prepared post.
	 * @param \WP_REST_Request     $request  Request.
	 * @return \stdClass|\WP_Error
	 */
	public function rest_check_attachment_meta( $prepared, $request ) {
		$meta = $request->get_param( 'meta' );
		if ( is_wp_error( $prepared ) || ! is_array( $meta ) ) {
			return $prepared;
		}

		$post_id = (int) ( $prepared->ID ?? 0 );
		$checks  = [
			'audio_id'           => [ AudioMetadata::class, 'is_valid_audio_attachment' ],
			'transcript_file_id' => [ EpisodeMeta::class, 'is_transcript_attachment' ],
			'artwork_id'         => [ EpisodeMeta::class, 'is_image_attachment' ],
			'guest_image_id'     => [ EpisodeMeta::class, 'is_image_attachment' ],
		];

		foreach ( $checks as $key => $is_valid ) {
			$name = Episodes::META_PREFIX . $key;
			if ( ! array_key_exists( $name, $meta ) ) {
				continue;
			}

			$id = absint( $meta[ $name ] );
			if ( $id <= 0 || ( $post_id > 0 && (int) get_post_meta( $post_id, $name, true ) === $id ) ) {
				continue;
			}

			if ( ! $is_valid( $id ) ) {
				return new \WP_Error( 'rest_invalid_meta', __( 'This attachment is not a supported file type for this episode field.', 'elementor-podcast-manager' ), [ 'status' => 400 ] );
			}
			if ( ! Capabilities::can_use_attachment( $id ) ) {
				return new \WP_Error(
					'rest_forbidden_meta',
					__( 'You can’t use this file for the episode.', 'elementor-podcast-manager' ),
					[ 'status' => 403 ]
				);
			}
		}

		return $prepared;
	}

	/**
	 * Hide episode meta in REST responses for password-protected episodes
	 * the requester has not unlocked and cannot edit.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function protect_rest_meta( $response, $post, $request ) {
		if ( $response instanceof \WP_REST_Response && $post instanceof \WP_Post
			&& post_password_required( $post ) && ! $this->request_unlocked( $post, $request ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			$data = $response->get_data();
			if ( isset( $data['meta'] ) ) {
				$data['meta'] = [];
				$response->set_data( $data );
			}
		}

		return $response;
	}

	/**
	 * Whether this REST request supplied the episode's correct password.
	 *
	 * @param \WP_Post         $post    Episode.
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function request_unlocked( \WP_Post $post, $request ): bool {
		if ( ! $request instanceof \WP_REST_Request ) {
			return false;
		}
		$password = (string) $request->get_param( 'password' );
		return '' !== $password && hash_equals( (string) $post->post_password, $password );
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
