<?php
/**
 * Setup assistant, Hosting & import, and Distribution screens.
 *
 * Views live in admin/views/{setup,hosting,distribution}.php; this class
 * registers the screens, their assets, the AJAX endpoints the screens use,
 * the first-run redirect and the admin notices about setup and syncing.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminPages {

	/**
	 * Setup state option.
	 */
	public const SETUP_OPTION = 'epm_setup';

	/**
	 * Set on activation; consumed by the first admin request.
	 */
	public const REDIRECT_OPTION = 'epm_activation_redirect';

	private const NONCE = 'epm_setup';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// After Admin::register_menu (priority 10), so the order is stable.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_action( 'wp_ajax_epm_setup_save', [ $this, 'ajax_setup_save' ] );
		add_action( 'wp_ajax_epm_distribution_save', [ $this, 'ajax_distribution_save' ] );
		add_action( 'wp_ajax_epm_setup_dismiss', [ $this, 'ajax_setup_dismiss' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( EPM_FILE ), [ $this, 'plugin_links' ] );
	}

	/**
	 * Setup state.
	 *
	 * @return array{done: bool, path: string, dismissed: bool, page_id: int}
	 */
	public static function setup_state(): array {
		$state = get_option( self::SETUP_OPTION, [] );
		$state = is_array( $state ) ? $state : [];

		return [
			'done'      => ! empty( $state['done'] ),
			'path'      => in_array( $state['path'] ?? '', [ 'new', 'move', 'external' ], true ) ? (string) $state['path'] : '',
			'dismissed' => ! empty( $state['dismissed'] ),
			'page_id'   => (int) ( $state['page_id'] ?? 0 ),
		];
	}

	/**
	 * Merge values into the setup state.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return void
	 */
	public static function update_setup_state( array $values ): void {
		update_option( self::SETUP_OPTION, array_merge( self::setup_state(), $values ), false );
	}

	/**
	 * Whether the setup assistant should be offered.
	 *
	 * @return bool
	 */
	public static function needs_setup(): bool {
		$state = self::setup_state();

		return ! $state['done'] && ! $state['dismissed'] && ! epm()->settings->is_configured();
	}

	/**
	 * Register the screens.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$cap = Capabilities::manage_podcast();

		add_submenu_page(
			'epm-dashboard',
			__( 'Setup assistant', 'elementor-podcast-manager' ),
			__( 'Setup assistant', 'elementor-podcast-manager' ),
			$cap,
			'epm-setup',
			[ $this, 'render_setup' ],
			1
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Hosting & import', 'elementor-podcast-manager' ),
			__( 'Hosting & import', 'elementor-podcast-manager' ),
			$cap,
			'epm-hosting',
			[ $this, 'render_hosting' ]
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Distribution', 'elementor-podcast-manager' ),
			__( 'Distribution', 'elementor-podcast-manager' ),
			$cap,
			'epm-distribution',
			[ $this, 'render_distribution' ]
		);
	}

	/**
	 * Plugin list: a direct link to the setup assistant or settings.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public function plugin_links( array $links ): array {
		$url   = self::needs_setup() ? admin_url( 'admin.php?page=epm-setup' ) : admin_url( 'admin.php?page=epm-dashboard' );
		$label = self::needs_setup() ? __( 'Set up podcast', 'elementor-podcast-manager' ) : __( 'Podcast', 'elementor-podcast-manager' );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' );

		return $links;
	}

	/**
	 * Screen IDs of the three screens.
	 *
	 * @param string $hook Hook suffix.
	 * @return string setup|hosting|distribution|''
	 */
	private static function screen_key( string $hook ): string {
		foreach ( [ 'setup', 'hosting', 'distribution' ] as $key ) {
			if ( str_ends_with( $hook, '_page_epm-' . $key ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Assets for the three screens.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		$key = self::screen_key( $hook );

		// The shared component styles are used on the dashboard too.
		if ( '' !== $key || 'toplevel_page_epm-dashboard' === $hook ) {
			wp_enqueue_style( 'epm-app', EPM_URL . 'admin/css/epm-app.css', [], EPM_VERSION );
		}

		if ( '' === $key ) {
			return;
		}

		if ( 'setup' === $key ) {
			wp_enqueue_media();
		}

		$file = EPM_PATH . 'admin/js/epm-' . $key . '.js';
		if ( file_exists( $file ) ) {
			wp_enqueue_script( 'epm-' . $key, EPM_URL . 'admin/js/epm-' . $key . '.js', [ 'wp-i18n' ], EPM_VERSION, true );
			wp_set_script_translations( 'epm-' . $key, 'elementor-podcast-manager', EPM_PATH . 'languages' );
			wp_localize_script( 'epm-' . $key, 'epmApp', $this->script_data( $key ) );
		}

		$css = EPM_PATH . 'admin/css/epm-' . $key . '.css';
		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'epm-' . $key, EPM_URL . 'admin/css/epm-' . $key . '.css', [ 'epm-app' ], EPM_VERSION );
		}
	}

	/**
	 * Data for the screen scripts.
	 *
	 * @param string $key Screen key.
	 * @return array<string, mixed>
	 */
	private function script_data( string $key ): array {
		$providers = [];
		foreach ( Providers::all() as $id => $provider ) {
			$providers[ $id ] = [
				'name'     => (string) $provider['name'],
				'example'  => (string) $provider['feed_example'],
				'feedHelp' => (string) $provider['feed_help'],
				'redirect' => (string) $provider['redirect_help'],
			];
		}

		return [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE ),
			'importNonce'  => ImportJob::nonce(),
			'screen'       => $key,
			'feedUrl'      => Feed::url(),
			'publicFeed'   => Hosting::public_feed_url(),
			'providers'    => $providers,
			'hosting'      => Hosting::all(),
			'job'          => ImportJob::client_state( ImportJob::get() ),
			'setup'        => self::setup_state(),
			'dashboardUrl' => admin_url( 'admin.php?page=epm-dashboard' ),
			'episodesUrl'  => admin_url( 'edit.php?post_type=' . EpisodePostType::CPT ),
			'newEpisode'   => admin_url( 'post-new.php?post_type=' . EpisodePostType::CPT ),
			'strings'      => [
				'saving'      => __( 'Saving…', 'elementor-podcast-manager' ),
				'saved'       => __( 'Saved', 'elementor-podcast-manager' ),
				'copied'      => __( 'Copied', 'elementor-podcast-manager' ),
				'checking'    => __( 'Checking the feed…', 'elementor-podcast-manager' ),
				'importing'   => __( 'Importing…', 'elementor-podcast-manager' ),
				'syncing'     => __( 'Syncing…', 'elementor-podcast-manager' ),
				'failed'      => __( 'Something went wrong. Check your connection and try again.', 'elementor-podcast-manager' ),
				'chooseImage' => __( 'Choose podcast artwork', 'elementor-podcast-manager' ),
				'useImage'    => __( 'Use this image', 'elementor-podcast-manager' ),
				/* translators: 1: episodes done, 2: total episodes */
				'progress'    => __( '%1$s of %2$s episodes', 'elementor-podcast-manager' ),
				'leaveImport' => __( 'The import continues in the background if you leave this page.', 'elementor-podcast-manager' ),
			],
		];
	}

	/**
	 * First admin request after activation: open the setup assistant on a
	 * site that has no podcast yet. Never on bulk activation or AJAX.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		delete_option( self::REDIRECT_OPTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of core's bulk activation flag.
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() || ! Capabilities::can_manage_podcast() ) {
			return;
		}

		if ( ! self::needs_setup() || epm()->episodes->count_published() > 0 ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=epm-setup' ) );
		exit;
	}

	/**
	 * Admin notices: unfinished setup, failing sync.
	 *
	 * @return void
	 */
	public function notices(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$ours   = false !== strpos( $id, 'epm' ) || ( $screen && EpisodePostType::CPT === $screen->post_type );

		if ( $ours && ! str_ends_with( $id, 'epm-setup' ) && self::needs_setup() ) {
			printf(
				'<div class="notice notice-info epm-setup-notice" data-epm-setup-notice><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <button type="button" class="button-link" data-epm-setup-dismiss data-nonce="%5$s">%6$s</button></p></div>',
				esc_html__( 'Your podcast is not set up yet.', 'elementor-podcast-manager' ),
				esc_html__( 'The setup assistant walks you through hosting, show details, artwork and getting listed on Apple Podcasts and Spotify.', 'elementor-podcast-manager' ),
				esc_url( admin_url( 'admin.php?page=epm-setup' ) ),
				esc_html__( 'Start setup', 'elementor-podcast-manager' ),
				esc_attr( wp_create_nonce( self::NONCE ) ),
				esc_html__( 'Dismiss', 'elementor-podcast-manager' )
			);
		}

		$state = Hosting::state();
		if ( Hosting::sync_enabled() && (int) $state['failures'] >= 3 && ( $ours || 'dashboard' === $id ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Episodes are not syncing from your podcast host.', 'elementor-podcast-manager' ),
				esc_html( (string) $state['message'] ),
				esc_url( admin_url( 'admin.php?page=epm-hosting' ) ),
				esc_html__( 'Check the hosting settings', 'elementor-podcast-manager' )
			);
		}
	}

	/**
	 * AJAX guard.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Reload the page and try again.', 'elementor-podcast-manager' ) ], 403 );
		}
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to change the podcast setup.', 'elementor-podcast-manager' ) ], 403 );
		}
	}

	/**
	 * AJAX: hide the setup notice.
	 *
	 * @return void
	 */
	public function ajax_setup_dismiss(): void {
		$this->guard();
		self::update_setup_state( [ 'dismissed' => true ] );
		wp_send_json_success();
	}

	/**
	 * AJAX: save one step of the setup assistant.
	 *
	 * Steps: path, hosting, show, design, finish. Every step merges into the
	 * existing settings and runs through the regular sanitizers.
	 *
	 * @return void
	 */
	public function ajax_setup_save(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( (string) $_POST['step'] ) ) : '';
		$data = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per step below.
		// phpcs:enable

		$result = $this->save_step( $step, (array) $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Save one step.
	 *
	 * @param string               $step Step.
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_step( string $step, array $data ) {
		switch ( $step ) {
			case 'path':
				$path = in_array( $data['path'] ?? '', [ 'new', 'move', 'external' ], true ) ? (string) $data['path'] : '';
				if ( '' === $path ) {
					return new \WP_Error( 'epm_setup_path', __( 'Choose how your podcast is hosted.', 'elementor-podcast-manager' ) );
				}
				self::update_setup_state( [ 'path' => $path ] );
				$hosting         = Hosting::all();
				$hosting['mode'] = 'external' === $path ? 'external' : 'self';
				update_option( Hosting::OPTION, Hosting::sanitize( $hosting ) );
				return [ 'path' => $path ];

			case 'hosting':
				$hosting = array_merge( Hosting::all(), array_intersect_key( $data, Hosting::defaults() ) );
				foreach ( [ 'sync', 'redirect' ] as $flag ) {
					if ( isset( $data[ $flag ] ) ) {
						$hosting[ $flag ] = in_array( $data[ $flag ], [ '1', 1, true, 'true', 'on' ], true );
					}
				}
				$hosting = Hosting::sanitize( $hosting );
				update_option( Hosting::OPTION, $hosting );
				return [ 'hosting' => $hosting ];

			case 'show':
				$settings = epm()->settings;
				$allowed  = [ 'title', 'description', 'short_description', 'author', 'host', 'owner_name', 'owner_email', 'category', 'language', 'explicit', 'type', 'artwork_id', 'copyright', 'website_url' ];
				$current  = $settings->all();
				$next     = array_merge( $current, array_intersect_key( $data, array_flip( $allowed ) ) );
				// The select sends "Category::Subcategory"; a changed category
				// must not keep the old subcategory.
				if ( isset( $data['category'] ) ) {
					$next['subcategory'] = '';
				}
				$clean = $settings->sanitize( $next );
				update_option( PodcastSettings::OPTION, $clean );
				return [
					'settings'  => array_intersect_key( $clean, array_flip( array_merge( $allowed, [ 'subcategory' ] ) ) ),
					'artwork'   => self::artwork_check( (int) $clean['artwork_id'] ),
					'readiness' => Readiness::report(),
				];

			case 'design':
				$preset = sanitize_key( (string) ( $data['preset'] ?? '' ) );
				if ( '' !== $preset && null !== epm()->presets->get( $preset ) ) {
					epm()->design->apply_preset( $preset );
				}
				$out = [ 'preset' => (string) epm()->design->get( 'preset' ) ];
				if ( ! empty( $data['create_page'] ) && in_array( $data['create_page'], [ '1', 1, true, 'true', 'on' ], true ) ) {
					$page = self::create_podcast_page();
					if ( is_wp_error( $page ) ) {
						return $page;
					}
					$out['page'] = $page;
				}
				return $out;

			case 'finish':
				self::update_setup_state( [ 'done' => true ] );
				return [
					'done'      => true,
					'readiness' => Readiness::report(),
				];
		}

		return new \WP_Error( 'epm_setup_step', __( 'Unknown setup step.', 'elementor-podcast-manager' ) );
	}

	/**
	 * Directory requirements for the podcast artwork.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array{ok: bool, url: string, width: int, height: int, messages: array<int, string>}
	 */
	public static function artwork_check( int $attachment_id ): array {
		$out = [
			'ok'       => false,
			'url'      => '',
			'width'    => 0,
			'height'   => 0,
			'messages' => [],
		];

		if ( $attachment_id <= 0 ) {
			$out['messages'][] = __( 'Add square artwork: Apple Podcasts and Spotify require it.', 'elementor-podcast-manager' );
			return $out;
		}

		$out['url'] = (string) wp_get_attachment_image_url( $attachment_id, 'medium' );
		$mime       = (string) get_post_mime_type( $attachment_id );
		$meta       = wp_get_attachment_metadata( $attachment_id );
		$width      = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$height     = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;

		$out['width']  = $width;
		$out['height'] = $height;

		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			$out['messages'][] = __( 'Use a JPEG or PNG file.', 'elementor-podcast-manager' );
		}
		if ( $width > 0 && $width !== $height ) {
			/* translators: 1: width, 2: height */
			$out['messages'][] = sprintf( __( 'The image is %1$d×%2$d px. It must be exactly square.', 'elementor-podcast-manager' ), $width, $height );
		}
		if ( $width > 0 && ( $width < 1400 || $width > 3000 ) ) {
			/* translators: %d: width in pixels */
			$out['messages'][] = sprintf( __( 'The image is %d px wide. Use 1400 to 3000 px (3000 px is best).', 'elementor-podcast-manager' ), $width );
		}

		$out['ok'] = empty( $out['messages'] );

		return $out;
	}

	/**
	 * Create (once) a "Podcast" page showing the show, the latest episode,
	 * the episode list and the subscribe links.
	 *
	 * Uses the plugin's shortcodes so it works with every theme and editor;
	 * the page can be rebuilt in Elementor with the podcast widgets later.
	 *
	 * @return array{id: int, url: string, edit: string}|\WP_Error
	 */
	public static function create_podcast_page() {
		$state = self::setup_state();
		if ( $state['page_id'] > 0 && get_post( $state['page_id'] ) && 'trash' !== get_post_status( $state['page_id'] ) ) {
			return [
				'id'   => $state['page_id'],
				'url'  => (string) get_permalink( $state['page_id'] ),
				'edit' => (string) get_edit_post_link( $state['page_id'], 'raw' ),
			];
		}

		$title   = (string) epm()->settings->get( 'title' );
		$content = implode(
			"\n\n",
			[
				'<!-- wp:shortcode -->[podcast_latest]<!-- /wp:shortcode -->',
				'<!-- wp:shortcode -->[podcast_subscribe]<!-- /wp:shortcode -->',
				'<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'All episodes', 'elementor-podcast-manager' ) . '</h2><!-- /wp:heading -->',
				'<!-- wp:shortcode -->[podcast_episodes]<!-- /wp:shortcode -->',
			]
		);

		$page_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => '' !== $title ? $title : __( 'Podcast', 'elementor-podcast-manager' ),
					'post_name'    => 'listen',
					'post_content' => $content,
				]
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		self::update_setup_state( [ 'page_id' => (int) $page_id ] );

		return [
			'id'   => (int) $page_id,
			'url'  => (string) get_permalink( (int) $page_id ),
			'edit' => (string) get_edit_post_link( (int) $page_id, 'raw' ),
		];
	}

	/**
	 * AJAX: save a directory's progress (submitted / listed + listing URL).
	 *
	 * @return void
	 */
	public function ajax_distribution_save(): void {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$id     = isset( $_POST['directory'] ) ? sanitize_key( wp_unslash( (string) $_POST['directory'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		// phpcs:enable

		if ( null === Directories::get( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'elementor-podcast-manager' ) ] );
		}

		wp_send_json_success( Directories::save_progress( $id, $status, $url ) );
	}

	/**
	 * Setup assistant screen.
	 *
	 * @return void
	 */
	public function render_setup(): void {
		$this->render( 'setup' );
	}

	/**
	 * Hosting & import screen.
	 *
	 * @return void
	 */
	public function render_hosting(): void {
		$this->render( 'hosting' );
	}

	/**
	 * Distribution screen.
	 *
	 * @return void
	 */
	public function render_distribution(): void {
		$this->render( 'distribution' );
	}

	/**
	 * Render a view.
	 *
	 * @param string $view View name.
	 * @return void
	 */
	private function render( string $view ): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'elementor-podcast-manager' ) );
		}

		require EPM_PATH . 'admin/views/' . $view . '.php';
	}
}
