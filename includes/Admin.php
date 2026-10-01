<?php
/**
 * Admin UI: menu, dashboard, episode list columns.
 * Settings and Design screens render through dedicated view files.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_filter( 'manage_' . EpisodePostType::CPT . '_posts_columns', [ $this, 'list_columns' ] );
		add_filter( 'default_hidden_columns', [ $this, 'default_hidden_columns' ], 10, 2 );
		add_action( 'manage_' . EpisodePostType::CPT . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-' . EpisodePostType::CPT . '_sortable_columns', [ $this, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_admin_orderby' ] );
		add_filter( 'parent_file', [ $this, 'menu_highlight' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_highlight' ], 10, 2 );
		add_filter( 'post_updated_messages', [ $this, 'updated_messages' ] );
		add_filter( 'bulk_post_updated_messages', [ $this, 'bulk_updated_messages' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_epm_design_export', [ $this, 'handle_design_export' ] );
		add_action( 'admin_post_epm_design_import', [ $this, 'handle_design_import' ] );
		add_action( 'admin_post_epm_design_preset', [ $this, 'handle_design_preset' ] );
		add_action( 'admin_post_epm_design_details', [ $this, 'handle_design_details' ] );

		// Quick Edit and Bulk Edit in the episode list.
		add_action( 'quick_edit_custom_box', [ $this, 'quick_edit_box' ], 10, 2 );
		add_action( 'bulk_edit_custom_box', [ $this, 'bulk_edit_box' ], 10, 2 );
		add_action( 'save_post_' . EpisodePostType::CPT, [ $this, 'save_inline_edit' ], 20, 2 );

		$meta = new EpisodeMeta();
		$meta->init();
	}

	/**
	 * Map custom sortable columns to meta queries in the admin list.
	 *
	 * @param \WP_Query $query Query object.
	 * @return void
	 */
	public function apply_admin_orderby( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( EpisodePostType::CPT !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		$keys    = [
			'epm_episode_number' => Episodes::META_PREFIX . 'episode_number',
			'epm_duration'       => Episodes::META_PREFIX . 'duration_seconds',
		];

		if ( ! is_string( $orderby ) || ! isset( $keys[ $orderby ] ) ) {
			return;
		}

		// Named clause + NOT EXISTS: sort numerically without dropping
		// episodes that have no value for the key.
		$query->set(
			'meta_query',
			[
				'relation'     => 'OR',
				'epm_sort_key' => [
					'key'  => $keys[ $orderby ],
					'type' => 'NUMERIC',
				],
				[
					'key'     => $keys[ $orderby ],
					'compare' => 'NOT EXISTS',
				],
			]
		);
		$query->set( 'orderby', 'epm_sort_key' );
	}

	/**
	 * Episode-specific editor messages ("Episode published." instead of
	 * WordPress's generic "Post published.").
	 *
	 * @param array $messages Messages per post type.
	 * @return array
	 */
	public function updated_messages( array $messages ): array {
		global $post;

		$link = '';
		if ( $post instanceof \WP_Post && is_post_type_viewable( EpisodePostType::CPT ) ) {
			$link = sprintf( ' <a href="%s">%s</a>', esc_url( get_permalink( $post ) ), esc_html__( 'View episode', 'elementor-podcast-manager' ) );
		}

		$scheduled_for = $post instanceof \WP_Post
			? date_i18n( __( 'M j, Y @ H:i', 'elementor-podcast-manager' ), strtotime( $post->post_date ) )
			: '';

		$messages[ EpisodePostType::CPT ] = [
			0  => '',
			1  => __( 'Episode updated.', 'elementor-podcast-manager' ) . $link,
			2  => __( 'Custom field updated.', 'elementor-podcast-manager' ),
			3  => __( 'Custom field deleted.', 'elementor-podcast-manager' ),
			4  => __( 'Episode updated.', 'elementor-podcast-manager' ),
			5  => isset( $_GET['revision'] ) ? __( 'Episode restored from a revision.', 'elementor-podcast-manager' ) : false, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			6  => __( 'Episode published. It is now in the podcast feed if it has MP3 or M4A audio.', 'elementor-podcast-manager' ) . $link,
			7  => __( 'Episode saved.', 'elementor-podcast-manager' ),
			8  => __( 'Episode submitted for review.', 'elementor-podcast-manager' ),
			/* translators: %s: scheduled date */
			9  => sprintf( __( 'Episode scheduled for %s. It joins the podcast feed automatically at that time.', 'elementor-podcast-manager' ), '<strong>' . esc_html( $scheduled_for ) . '</strong>' ),
			10 => __( 'Episode draft updated.', 'elementor-podcast-manager' ),
		];

		return $messages;
	}

	/**
	 * Episode-specific bulk action messages.
	 *
	 * @param array $bulk_messages Messages per post type.
	 * @param array $bulk_counts   Counts per action.
	 * @return array
	 */
	public function bulk_updated_messages( array $bulk_messages, array $bulk_counts ): array {
		$bulk_messages[ EpisodePostType::CPT ] = [
			/* translators: %s: number of episodes */
			'updated'   => _n( '%s episode updated.', '%s episodes updated.', $bulk_counts['updated'], 'elementor-podcast-manager' ),
			/* translators: %s: number of episodes */
			'locked'    => _n( '%s episode not updated, somebody is editing it.', '%s episodes not updated, somebody is editing them.', $bulk_counts['locked'], 'elementor-podcast-manager' ),
			/* translators: %s: number of episodes */
			'deleted'   => _n( '%s episode permanently deleted.', '%s episodes permanently deleted.', $bulk_counts['deleted'], 'elementor-podcast-manager' ),
			/* translators: %s: number of episodes */
			'trashed'   => _n( '%s episode moved to the Trash.', '%s episodes moved to the Trash.', $bulk_counts['trashed'], 'elementor-podcast-manager' ),
			/* translators: %s: number of episodes */
			'untrashed' => _n( '%s episode restored from the Trash.', '%s episodes restored from the Trash.', $bulk_counts['untrashed'], 'elementor-podcast-manager' ),
		];

		return $bulk_messages;
	}

	/**
	 * Keep the Podcast menu highlighted on episode screens.
	 *
	 * @param string $parent_file Parent file.
	 * @return string
	 */
	public function menu_highlight( string $parent_file ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ( EpisodePostType::CPT === $screen->post_type || EpisodePostType::TOPIC === $screen->taxonomy ) ) {
			return 'epm-dashboard';
		}

		return $parent_file;
	}

	/**
	 * Highlight "Topics" under the Podcast menu on the topic screens.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file  Parent file.
	 * @return string|null
	 */
	public function submenu_highlight( $submenu_file, $parent_file = '' ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && EpisodePostType::TOPIC === $screen->taxonomy ) {
			return self::topics_menu_slug();
		}

		return $submenu_file;
	}

	/**
	 * Admin URL path of the topic management screen (also its menu slug).
	 *
	 * @return string
	 */
	public static function topics_menu_slug(): string {
		return 'edit-tags.php?taxonomy=' . EpisodePostType::TOPIC . '&post_type=' . EpisodePostType::CPT;
	}

	/**
	 * Enqueue admin CSS/JS on our screens.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( strpos( $hook, 'epm' ) === false && strpos( $hook, 'podcast' ) === false ) {
			return;
		}

		wp_enqueue_media();
		self::enqueue_assets();

		if ( str_ends_with( $hook, '_page_epm-design' ) ) {
			self::enqueue_design_assets();
		}
	}

	/**
	 * Design screen: the shared app components, the frontend stylesheet
	 * (the live preview renders the real components) and the screen's own
	 * script, which maps every field to its --epm-* variable with the data
	 * from design_preview_config().
	 *
	 * @return void
	 */
	public static function enqueue_design_assets(): void {
		if ( ! wp_style_is( 'epm-frontend', 'registered' ) ) {
			wp_register_style( 'epm-frontend', EPM_URL . 'assets/css/epm-frontend.css', [], EPM_VERSION );
		}

		wp_enqueue_style( 'epm-app', EPM_URL . 'admin/css/epm-app.css', [], EPM_VERSION );
		wp_enqueue_style( 'epm-design', EPM_URL . 'admin/css/epm-design.css', [ 'epm-admin', 'epm-app', 'epm-frontend' ], EPM_VERSION );
		wp_enqueue_script( 'epm-design', EPM_URL . 'admin/js/epm-design.js', [ 'wp-i18n', 'wp-a11y' ], EPM_VERSION, true );
		wp_set_script_translations( 'epm-design', 'elementor-podcast-manager', EPM_PATH . 'languages' );
		wp_add_inline_script(
			'epm-design',
			'window.epmDesign = ' . wp_json_encode( self::design_preview_config() ) . ';',
			'before'
		);
	}

	/**
	 * Register and enqueue the classic admin stylesheet and script with
	 * their strings. Shared by the plugin screens and the episode editor
	 * and list (EpisodeMeta), so both get the same data.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( 'epm-admin', EPM_URL . 'admin/css/epm-admin.css', [ 'dashicons' ], EPM_VERSION );
		wp_enqueue_script( 'epm-admin', EPM_URL . 'admin/js/epm-admin.js', [ 'jquery', 'wp-a11y', 'wp-i18n' ], EPM_VERSION, true );

		if ( wp_script_is( 'epm-admin', 'done' ) || ! empty( wp_scripts()->get_data( 'epm-admin', 'data' ) ) ) {
			return;
		}

		wp_set_script_translations( 'epm-admin', 'elementor-podcast-manager', EPM_PATH . 'languages' );
		wp_localize_script( 'epm-admin', 'epmAdmin', self::script_data() );
	}

	/**
	 * Strings and settings for admin/js/epm-admin.js.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data(): array {
		return [
			'nonce'      => wp_create_nonce( 'epm_episode_meta' ),
			'setupNonce' => wp_create_nonce( 'epm_setup' ),
			'choose'     => __( 'Choose audio file', 'elementor-podcast-manager' ),
			'use'        => __( 'Use this audio', 'elementor-podcast-manager' ),
			'useImage'   => __( 'Use this image', 'elementor-podcast-manager' ),
			'strings'    => [
				'uploadAudio'     => __( 'Upload episode audio', 'elementor-podcast-manager' ),
				'replaceAudio'    => __( 'Replace audio', 'elementor-podcast-manager' ),
				'remove'          => __( 'Remove audio', 'elementor-podcast-manager' ),
				'dropHint'        => __( 'Drop an MP3 or M4A file here, or', 'elementor-podcast-manager' ),
				/* translators: %s: file name */
				'uploadingFile'   => __( 'Uploading %s…', 'elementor-podcast-manager' ),
				'uploading'       => __( 'Uploading…', 'elementor-podcast-manager' ),
				'readingFile'     => __( 'Reading the file details…', 'elementor-podcast-manager' ),
				'uploadFailed'    => __( 'The upload failed. Check your connection and try again.', 'elementor-podcast-manager' ),
				'describeFailed'  => __( 'The file details could not be read. Choose the file again.', 'elementor-podcast-manager' ),
				'invalidType'     => __( 'This file type can’t be used. Drop an MP3 or M4A file (WAV is kept for storage only).', 'elementor-podcast-manager' ),
				'wavWarning'      => __( 'Podcast apps won’t get this file. Replace it with an MP3 or M4A to include this episode in your feed.', 'elementor-podcast-manager' ),
				/* translators: %s: file name */
				'audioAttached'   => __( 'Audio attached: %s', 'elementor-podcast-manager' ),
				'audioRemoved'    => __( 'Audio removed.', 'elementor-podcast-manager' ),
				'audioUrl'        => __( 'Audio URL', 'elementor-podcast-manager' ),
				'checkingUrl'     => __( 'Checking the audio URL…', 'elementor-podcast-manager' ),
				/* translators: %s: host name */
				'urlChecked'      => __( 'Audio URL checked: %s', 'elementor-podcast-manager' ),
				'urlInvalid'      => __( 'Enter a web address that starts with https://, for example https://cdn.example.com/episode-12.mp3.', 'elementor-podcast-manager' ),
				'urlCheckFailed'  => __( 'The audio URL could not be checked. It will be checked again when you save.', 'elementor-podcast-manager' ),
				/* translators: %s: host name */
				'externalNote'    => __( 'This file stays at %s. Keep it online at this address: the feed and the player load it from there.', 'elementor-podcast-manager' ),
				'mediaWins'       => __( 'Not used while a Media Library file is attached. Remove that file to use this address.', 'elementor-podcast-manager' ),
				/* translators: %s: detected duration */
				'durationDetected' => __( 'Detected from the file: %s. Enter a duration only if this is wrong (mm:ss or hh:mm:ss).', 'elementor-podcast-manager' ),
				'durationExternal' => __( 'Enter the duration as mm:ss or hh:mm:ss. It can’t be read from an audio URL.', 'elementor-podcast-manager' ),
				'durationAuto'    => __( 'Detected automatically when you add audio. Enter it only if detection fails (mm:ss or hh:mm:ss).', 'elementor-podcast-manager' ),
				'copied'          => __( 'Copied', 'elementor-podcast-manager' ),
				'copiedMessage'   => __( 'Copied to the clipboard.', 'elementor-podcast-manager' ),
				'copyFailed'      => __( 'Couldn’t copy automatically. The address is selected: press Ctrl+C (⌘C on a Mac) to copy it.', 'elementor-podcast-manager' ),
				'chooseImage'     => __( 'Choose image', 'elementor-podcast-manager' ),
				'replaceImage'    => __( 'Replace image', 'elementor-podcast-manager' ),
				'imageSelected'   => __( 'Image selected.', 'elementor-podcast-manager' ),
				'imageRemoved'    => __( 'Image removed.', 'elementor-podcast-manager' ),
				'fixField'        => __( 'Fix the highlighted field, then save again.', 'elementor-podcast-manager' ),
				'setupDismissed'  => __( 'Setup reminder hidden. You can start the setup assistant from the Podcast menu at any time.', 'elementor-podcast-manager' ),
			],
		];
	}

	/**
	 * Register the Podcast menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Podcast', 'elementor-podcast-manager' ),
			__( 'Podcast', 'elementor-podcast-manager' ),
			Capabilities::manage_episodes(),
			'epm-dashboard',
			[ $this, 'render_dashboard' ],
			'dashicons-microphone',
			20
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Dashboard', 'elementor-podcast-manager' ),
			__( 'Dashboard', 'elementor-podcast-manager' ),
			Capabilities::manage_episodes(),
			'epm-dashboard',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Episodes', 'elementor-podcast-manager' ),
			__( 'Episodes', 'elementor-podcast-manager' ),
			Capabilities::manage_episodes(),
			'edit.php?post_type=' . EpisodePostType::CPT
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Add episode', 'elementor-podcast-manager' ),
			__( 'Add episode', 'elementor-podcast-manager' ),
			Capabilities::manage_episodes(),
			'post-new.php?post_type=' . EpisodePostType::CPT
		);

		$topics = get_taxonomy( EpisodePostType::TOPIC );
		if ( $topics ) {
			// Only for users who may manage topics (like core Tags).
			add_submenu_page(
				'epm-dashboard',
				__( 'Topics', 'elementor-podcast-manager' ),
				__( 'Topics', 'elementor-podcast-manager' ),
				$topics->cap->manage_terms,
				self::topics_menu_slug()
			);
		}

		add_submenu_page(
			'epm-dashboard',
			__( 'Podcast settings', 'elementor-podcast-manager' ),
			__( 'Podcast settings', 'elementor-podcast-manager' ),
			Capabilities::manage_podcast(),
			'epm-settings',
			[ $this, 'render_settings' ]
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Design', 'elementor-podcast-manager' ),
			__( 'Design', 'elementor-podcast-manager' ),
			Capabilities::manage_podcast(),
			'epm-design',
			[ $this, 'render_design' ]
		);
	}

	/**
	 * Dashboard screen.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! Capabilities::can_manage_episodes() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'elementor-podcast-manager' ) );
		}

		$settings    = epm()->settings;
		$latest      = epm()->episodes->get_latest();
		$latest_data = $latest ? epm()->episodes->get_data( $latest ) : null;
		$count       = epm()->episodes->count_published();
		$counts      = wp_count_posts( EpisodePostType::CPT );
		$drafts      = (int) ( $counts->draft ?? 0 ) + (int) ( $counts->pending ?? 0 );
		$scheduled   = (int) ( $counts->future ?? 0 );
		$artwork     = $settings->artwork_url( 'medium' );
		$readiness   = Readiness::report();
		$can_manage  = Capabilities::can_manage_podcast();
		$needs_setup = $can_manage && AdminPages::needs_setup();
		$setup_state = AdminPages::setup_state();
		$page_url    = '';

		if ( $setup_state['page_id'] > 0 && 'publish' === get_post_status( $setup_state['page_id'] ) ) {
			$page_url = (string) get_permalink( $setup_state['page_id'] );
		}

		$hosting = [
			'external' => Hosting::is_external(),
			'provider' => Hosting::provider_name(),
			'feed'     => Hosting::public_feed_url(),
			'state'    => Hosting::state(),
			'sync'     => Hosting::sync_enabled(),
		];

		$distribution = self::distribution_summary();

		require EPM_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Progress on the essential directories: the ones every show should
	 * submit to itself (priority "essential", not listed via another one).
	 *
	 * @return array{total: int, done: int, next: string, next_name: string, rows: array<int, array{id: string, name: string, icon: string, status: string}>}
	 */
	public static function distribution_summary(): array {
		$progress = Directories::progress();
		$out      = [
			'total'     => 0,
			'done'      => 0,
			'next'      => '',
			'next_name' => '',
			'rows'      => [],
		];

		foreach ( Directories::all() as $id => $directory ) {
			if ( 'essential' !== ( $directory['priority'] ?? '' ) || '' !== (string) ( $directory['via'] ?? '' ) ) {
				continue;
			}

			$status = (string) ( $progress[ $id ]['status'] ?? '' );

			++$out['total'];
			if ( '' !== $status ) {
				++$out['done'];
			} elseif ( '' === $out['next'] ) {
				$out['next']      = (string) $id;
				$out['next_name'] = (string) $directory['name'];
			}

			$out['rows'][] = [
				'id'     => (string) $id,
				'name'   => (string) $directory['name'],
				'icon'   => (string) ( $directory['icon'] ?? '' ),
				'status' => $status,
			];
		}

		return $out;
	}

	/**
	 * Audio status of an episode for lists and cards.
	 *
	 * @param array<string, mixed> $data Episode data (Episodes::get_data()).
	 * @return array{key: string, label: string, title: string}
	 */
	public static function audio_status( array $data ): array {
		if ( empty( $data['has_audio'] ) ) {
			return [
				'key'   => 'missing',
				'label' => __( 'Missing audio', 'elementor-podcast-manager' ),
				'title' => __( 'Add an MP3 or M4A file or an audio URL to include this episode in the feed.', 'elementor-podcast-manager' ),
			];
		}

		if ( ! AudioMetadata::is_distribution_format( (string) ( $data['audio_mime'] ?? '' ) ) ) {
			return [
				'key'   => 'warning',
				'label' => __( 'Not in feed', 'elementor-podcast-manager' ),
				'title' => __( 'Only MP3 and M4A audio is included in the podcast feed.', 'elementor-podcast-manager' ),
			];
		}

		return [
			'key'   => 'ready',
			'label' => __( 'Ready', 'elementor-podcast-manager' ),
			'title' => '',
		];
	}

	/**
	 * Markup of a copy button: fixed width, the label cross-fades to
	 * "Copied" and the icon swaps (both kept in the DOM); the result is
	 * announced by admin/js/epm-admin.js through wp.a11y.speak().
	 *
	 * @param string $value   Text to copy.
	 * @param string $label   Button label (verb first).
	 * @param string $class   Extra button classes.
	 * @param string $message Announcement after copying.
	 * @return string Escaped HTML.
	 */
	public static function copy_button( string $value, string $label, string $class = '', string $message = '' ): string {
		return sprintf(
			'<button type="button" class="button epm-copy-button %1$s" data-epm-copy="%2$s"%3$s><span class="epm-copy-button__icons" aria-hidden="true"><span class="dashicons dashicons-admin-page epm-copy-button__icon-idle"></span><span class="dashicons dashicons-yes epm-copy-button__icon-done"></span></span><span class="epm-copy-button__labels"><span class="epm-copy-button__idle">%4$s</span><span class="epm-copy-button__done" aria-hidden="true">%5$s</span></span></button>',
			esc_attr( trim( $class ) ),
			esc_attr( $value ),
			'' !== $message ? ' data-epm-copied-message="' . esc_attr( $message ) . '"' : '',
			esc_html( $label ),
			esc_html__( 'Copied', 'elementor-podcast-manager' )
		);
	}

	/**
	 * Reorder buttons of a repeater row (24px targets, icon only, named).
	 *
	 * @param string $up_label   Accessible name of the "move up" button.
	 * @param string $down_label Accessible name of the "move down" button.
	 * @return void
	 */
	public static function repeat_order_buttons( string $up_label, string $down_label ): void {
		?>
		<span class="epm-repeat__order">
			<button type="button" class="button-link" data-epm-repeat-up aria-label="<?php echo esc_attr( $up_label ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
			<button type="button" class="button-link" data-epm-repeat-down aria-label="<?php echo esc_attr( $down_label ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
		</span>
		<?php
	}

	/**
	 * Remove button of a repeater row.
	 *
	 * @param string $label Accessible name.
	 * @return void
	 */
	public static function repeat_remove_button( string $label ): void {
		?>
		<button type="button" class="button-link epm-repeat__remove" data-epm-repeat-remove aria-label="<?php echo esc_attr( $label ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
		<?php
	}

	/**
	 * Data attributes with the announcements of a repeater.
	 *
	 * @param string $added   Row added.
	 * @param string $removed Row removed.
	 * @param string $moved   Row moved; %1$d position, %2$d total.
	 * @return string Escaped attributes.
	 */
	public static function repeat_messages( string $added, string $removed, string $moved ): string {
		return sprintf(
			' data-epm-msg-added="%1$s" data-epm-msg-removed="%2$s" data-epm-msg-moved="%3$s"',
			esc_attr( $added ),
			esc_attr( $removed ),
			esc_attr( $moved )
		);
	}

	/**
	 * One link row (service, button text, URL) for the link repeaters.
	 *
	 * @param string               $name_prefix Field name prefix, e.g. epm_podcast_settings[platform_links][0].
	 * @param string               $id_prefix   Unique ID prefix for the row.
	 * @param array<string, mixed> $link        Link data.
	 * @param array<string, string> $services   Service options (key => label).
	 * @param string               $error       Error shown next to the row.
	 * @return void
	 */
	public static function link_row( string $name_prefix, string $id_prefix, array $link, array $services, string $error = '' ): void {
		$error_id = $id_prefix . '-error';
		?>
		<div class="epm-repeat__row epm-repeat__row--links<?php echo '' !== $error ? ' is-invalid' : ''; ?>" data-epm-repeat-row>
			<?php self::repeat_order_buttons( __( 'Move link up', 'elementor-podcast-manager' ), __( 'Move link down', 'elementor-podcast-manager' ) ); ?>
			<label class="epm-repeat__field">
				<span class="epm-repeat__label"><?php esc_html_e( 'Platform', 'elementor-podcast-manager' ); ?></span>
				<select name="<?php echo esc_attr( $name_prefix ); ?>[service]">
					<?php foreach ( $services as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) ( $link['service'] ?? 'custom' ), (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="epm-repeat__field">
				<span class="epm-repeat__label"><?php esc_html_e( 'Button text', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></span>
				<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label]" value="<?php echo esc_attr( (string) ( $link['label'] ?? '' ) ); ?>" autocomplete="off" />
			</label>
			<label class="epm-repeat__field">
				<span class="epm-repeat__label"><?php esc_html_e( 'URL', 'elementor-podcast-manager' ); ?></span>
				<input type="url" name="<?php echo esc_attr( $name_prefix ); ?>[url]" value="<?php echo esc_attr( (string) ( $link['url'] ?? '' ) ); ?>" inputmode="url" spellcheck="false" autocomplete="off" data-epm-required data-epm-error="<?php esc_attr_e( 'Add the link’s web address, starting with https://, or remove this row.', 'elementor-podcast-manager' ); ?>"<?php echo '' !== $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $error_id ) . '"' : ''; ?> />
			</label>
			<?php self::repeat_remove_button( __( 'Remove link', 'elementor-podcast-manager' ) ); ?>
			<p class="epm-field-error epm-repeat__error" id="<?php echo esc_attr( $error_id ); ?>" data-epm-repeat-error<?php echo '' === $error ? ' hidden' : ''; ?>><?php echo esc_html( $error ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a repeatable label/url/service link list for the settings screens.
	 * Compatible with the generic admin JS repeater.
	 *
	 * @param string $key Setting key within epm_podcast_settings.
	 * @param array  $links Existing links.
	 * @return void
	 */
	public function links_repeater( string $key, array $links ): void {
		$services = wp_list_pluck( Directories::services(), 'label' );
		$prefix   = 'epm_podcast_settings[' . $key . ']';
		$id       = 'epm-' . sanitize_html_class( $key );
		?>
		<div class="epm-repeat" data-epm-repeat="links" id="<?php echo esc_attr( $id ); ?>"<?php echo self::repeat_messages( __( 'Link added.', 'elementor-podcast-manager' ), __( 'Link removed.', 'elementor-podcast-manager' ), /* translators: 1: new position, 2: number of links */ __( 'Link moved to position %1$d of %2$d.', 'elementor-podcast-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in repeat_messages(). ?>>
			<div class="epm-repeat__rows" data-epm-repeat-rows>
				<?php foreach ( array_values( $links ) as $index => $link ) : ?>
					<?php self::link_row( $prefix . '[' . $index . ']', $id . '-' . $index, (array) $link, $services ); ?>
				<?php endforeach; ?>
			</div>
			<template data-epm-repeat-template>
				<?php self::link_row( $prefix . '[__INDEX__]', $id . '-__INDEX__', [ 'service' => 'custom' ], $services ); ?>
			</template>
			<p>
				<button type="button" class="button" data-epm-repeat-add>
					<?php esc_html_e( 'Add link', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Media Library image picker: hidden ID field, preview, a choose button
	 * that reads "Replace image" once an image is set, and a named remove
	 * button that only shows when there is something to remove.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $name         Input name.
	 *     @type string $key          Picker key (data attribute, element ID).
	 *     @type int    $id           Attachment ID.
	 *     @type string $choose       Choose-button label.
	 *     @type string $replace      Replace-button label.
	 *     @type string $remove       Remove-button label.
	 *     @type string $frame_title  Media frame title.
	 *     @type string $size         Preview image size.
	 *     @type string $describedby  ID of a description element.
	 * }
	 * @return void
	 */
	public static function media_picker( array $args ): void {
		$args = wp_parse_args(
			$args,
			[
				'name'        => '',
				'key'         => '',
				'id'          => 0,
				'choose'      => __( 'Choose image', 'elementor-podcast-manager' ),
				'replace'     => __( 'Replace image', 'elementor-podcast-manager' ),
				'remove'      => __( 'Remove image', 'elementor-podcast-manager' ),
				'frame_title' => '',
				'size'        => 'thumbnail',
				'describedby' => '',
			]
		);

		$attachment_id = (int) $args['id'];
		$preview       = $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, (string) $args['size'], false, [ 'class' => 'epm-media-preview__img' ] ) : '';
		$has_image     = '' !== $preview;
		$frame_title   = '' !== (string) $args['frame_title'] ? (string) $args['frame_title'] : (string) $args['choose'];
		?>
		<div class="epm-media" data-epm-media="<?php echo esc_attr( (string) $args['key'] ); ?>" id="epm-media-<?php echo esc_attr( sanitize_html_class( (string) $args['key'] ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( (string) $args['name'] ); ?>" value="<?php echo esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' ); ?>" data-epm-media-id />
			<div class="epm-media-preview" data-epm-media-preview><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() output. ?></div>
			<p class="epm-media__actions">
				<button type="button" class="button" data-epm-media-choose data-title="<?php echo esc_attr( $frame_title ); ?>" data-choose-label="<?php echo esc_attr( (string) $args['choose'] ); ?>" data-replace-label="<?php echo esc_attr( (string) $args['replace'] ); ?>"<?php echo '' !== (string) $args['describedby'] ? ' aria-describedby="' . esc_attr( (string) $args['describedby'] ) . '"' : ''; ?>>
					<?php echo esc_html( $has_image ? (string) $args['replace'] : (string) $args['choose'] ); ?>
				</button>
				<button type="button" class="button-link epm-media__remove" data-epm-media-remove<?php echo $has_image ? '' : ' hidden'; ?>>
					<?php echo esc_html( (string) $args['remove'] ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a media-library image picker field for the settings screens.
	 *
	 * @param string $key           Setting key within epm_podcast_settings / epm_design_settings.
	 * @param int    $attachment_id Current attachment ID.
	 * @param string $button_label  Choose-button label.
	 * @param string $option_name   Option array name.
	 * @param string $remove_label  Remove-button label.
	 * @param string $describedby   ID of the field description.
	 * @return void
	 */
	public function media_field( string $key, int $attachment_id, string $button_label, string $option_name = 'epm_podcast_settings', string $remove_label = '', string $describedby = '' ): void {
		wp_enqueue_media();

		self::media_picker(
			[
				'name'        => $option_name . '[' . $key . ']',
				'key'         => $key,
				'id'          => $attachment_id,
				'choose'      => $button_label,
				'remove'      => '' !== $remove_label ? $remove_label : __( 'Remove image', 'elementor-podcast-manager' ),
				'describedby' => $describedby,
			]
		);
	}

	/**
	 * Podcast settings screen.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'elementor-podcast-manager' ) );
		}

		require EPM_PATH . 'admin/views/settings.php';
	}

	/**
	 * Design (global styles) screen.
	 *
	 * @return void
	 */
	public function render_design(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'elementor-podcast-manager' ) );
		}

		require EPM_PATH . 'admin/views/design.php';
	}

	/**
	 * Exported design token keys.
	 *
	 * EXPORT ALLOWLIST — the design option holds visual tokens ONLY:
	 * colors, sizes, layout names, shape/font/shadow choices, the preset id,
	 * and the site's details (which parts show, see Details). It never
	 * holds attachment IDs, post IDs, URLs, or content (artwork and links
	 * live in the podcast settings option, which is deliberately NOT
	 * exported). The list below is the complete set of exported token keys,
	 * so no environment-specific value can leak into a design file.
	 *
	 * @return string[]
	 */
	public static function design_export_keys(): array {
		return [
			'accent',
			'on_accent',
			'text',
			'muted',
			'background',
			'surface',
			'border_color',
			'track_color',
			'border_radius',
			'artwork_radius',
			'spacing',
			'button_shape',
			'shadow',
			'font_family',
			'default_player_layout',
			'default_episode_layout',
			'title_font_size',
			'meta_font_size',
		];
	}

	/**
	 * The design file (format 2): the saved tokens and the site's details.
	 *
	 * @return array<string, mixed>
	 */
	public static function design_export_payload(): array {
		$design = epm()->design;

		// Explicit allowlist: scalar visual tokens only.
		$tokens = [];
		foreach ( self::design_export_keys() as $key ) {
			$value = $design->get( $key );
			if ( null !== $value ) {
				$tokens[ $key ] = $value;
			}
		}

		return [
			'format'   => 'epm-design',
			'version'  => DesignSettings::EXPORT_VERSION,
			'exported' => gmdate( 'c' ),
			'preset'   => (string) $design->get( 'preset' ),
			'design'   => $tokens,
			// Only the details the site set; the rest are built-in defaults.
			'details'  => Details::sanitize_map( $design->get( 'details' ) ),
		];
	}

	/**
	 * Export the effective client design as a versioned JSON download.
	 *
	 * @return void
	 */
	public function handle_design_export(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to export the design.', 'elementor-podcast-manager' ) );
		}

		check_admin_referer( 'epm_design_export', '_epm_export_nonce' );

		$design  = epm()->design;
		$payload = self::design_export_payload();

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'The design could not be encoded.', 'elementor-podcast-manager' ) );
		}

		$filename = sanitize_file_name(
			sprintf(
				'epm-design-%s-%s.json',
				(string) $design->get( 'preset' ),
				gmdate( 'Y-m-d' )
			)
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		echo $json;
		exit;
	}

	/**
	 * Import a design JSON file exported by handle_design_export().
	 *
	 * Validation: JSON must parse, format must be 'epm-design', the version
	 * must be known (unknown future versions are rejected), and 'design'
	 * must be an array. Only allowlisted keys are read; every value then
	 * passes through DesignSettings::sanitize(), which validates colors,
	 * numbers, layouts and choices, and falls back to the stored preset id
	 * when the payload names an unregistered preset.
	 *
	 * @return void
	 */
	public function handle_design_import(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to import a design.', 'elementor-podcast-manager' ) );
		}

		check_admin_referer( 'epm_design_import', '_epm_import_nonce' );

		$fail = function ( string $message ): void {
			set_transient(
				'epm_design_import_error_' . get_current_user_id(),
				$message,
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect(
				add_query_arg( 'epm_design', 'import-error', admin_url( 'admin.php?page=epm-design' ) )
			);
			exit;
		};

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file upload array, validated below.
		$file = $_FILES['epm_design_file'] ?? null;

		if ( ! is_array( $file ) || ! isset( $file['error'] ) ) {
			$fail( __( 'Choose a design file (.json) to import.', 'elementor-podcast-manager' ) );
		}

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			$fail( __( 'The file upload failed. Choose the file again.', 'elementor-podcast-manager' ) );
		}

		$type = wp_check_filetype( (string) ( $file['name'] ?? '' ), [ 'json' => 'application/json' ] );
		if ( 'json' !== $type['ext'] ) {
			$fail( __( 'Choose a design file that ends in .json (exported from the Design screen).', 'elementor-podcast-manager' ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$fail( __( 'The uploaded file could not be read. Choose the file again.', 'elementor-podcast-manager' ) );
		}

		$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$fail( __( 'The uploaded file is empty. Export the design again and import the new file.', 'elementor-podcast-manager' ) );
		}

		if ( strlen( $raw ) > 256 * 1024 ) {
			$fail( __( 'The file is too large to be a design export. Choose the .json file exported from the Design screen.', 'elementor-podcast-manager' ) );
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$fail( __( 'The file is not valid JSON. Export the design again and import the new file.', 'elementor-podcast-manager' ) );
		}

		$result = self::design_from_payload( $data );

		if ( is_wp_error( $result ) ) {
			$fail( $result->get_error_message() );
		}

		update_option( DesignSettings::OPTION, $result );

		wp_safe_redirect(
			add_query_arg( 'epm_design', 'imported', admin_url( 'admin.php?page=epm-design' ) )
		);
		exit;
	}

	/**
	 * Validate a decoded design export and turn it into the option value.
	 *
	 * Checks: format 'epm-design', a known version (newer versions are
	 * rejected), a 'design' map. Only allowlisted token keys are read, and
	 * only scalar values (an array or object in a token is ignored, so it
	 * can never reach a string sanitizer). Every value then passes through
	 * DesignSettings::sanitize(): colors must be hex, sizes are clamped,
	 * layouts, button shapes, fonts and shadows must be known choices, and
	 * the track color is a hex value or '' (automatic). Keys a file does not
	 * carry (older exports) keep their stored values.
	 *
	 * Details: a format-2 file replaces the site's details with its own
	 * (known contexts and details, scalar values only). A format-1 file
	 * (1.1–1.3) carried the preset maps in preset_extras, which were never
	 * applied anywhere: they become suggestions (Details::from_legacy_maps())
	 * and the site's details stay as they are.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return array<string, mixed>|\WP_Error Sanitized option value.
	 */
	public static function design_from_payload( array $data ) {
		if ( ( $data['format'] ?? '' ) !== 'epm-design' ) {
			return new \WP_Error( 'epm_design_format', __( 'This file is not an Elementor Podcast Manager design export.', 'elementor-podcast-manager' ) );
		}

		$version = $data['version'] ?? 0;
		if ( ! is_int( $version ) || $version < 1 ) {
			return new \WP_Error( 'epm_design_version', __( 'The design file has no usable version number.', 'elementor-podcast-manager' ) );
		}
		if ( $version > DesignSettings::EXPORT_VERSION ) {
			return new \WP_Error(
				'epm_design_version',
				sprintf(
					/* translators: %d: file version */
					__( 'This design was exported with a newer format version (%d). Update the plugin on this site, then import it again.', 'elementor-podcast-manager' ),
					$version
				)
			);
		}

		if ( ! is_array( $data['design'] ?? null ) ) {
			return new \WP_Error( 'epm_design_empty', __( 'The design file contains no design values.', 'elementor-podcast-manager' ) );
		}

		$design = epm()->design;

		$input = array_filter(
			array_intersect_key( $data['design'], array_flip( self::design_export_keys() ) ),
			static function ( $value ): bool {
				return is_scalar( $value );
			}
		);
		$input['preset'] = is_scalar( $data['preset'] ?? null ) ? (string) $data['preset'] : '';
		$sanitized       = $design->sanitize( $input );

		if ( $version >= 2 ) {
			if ( array_key_exists( 'details', $data ) ) {
				$sanitized['details'] = Details::sanitize_map( is_array( $data['details'] ) ? $data['details'] : [] );
			}
			$sanitized['details_suggested'] = [];
		} else {
			$extras = is_array( $data['preset_extras'] ?? null ) ? $data['preset_extras'] : [];
			if ( ! empty( $extras ) ) {
				$sanitized['details_suggested'] = Details::from_legacy_maps( $extras['visibility'] ?? [], $extras['player'] ?? [], $extras['episodeList'] ?? [] );
			}
		}
		$sanitized['details_version'] = 1;

		return $sanitized;
	}

	/**
	 * Save the details form, apply or dismiss suggested details, or go back
	 * to the built-in details (POST from the Design screen).
	 *
	 * @return void
	 */
	public function handle_design_details(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to change the design.', 'elementor-podcast-manager' ) );
		}

		check_admin_referer( 'epm_design_details', '_epm_details_nonce' );

		$design = epm()->design;
		$do     = isset( $_POST['epm_details_action'] ) && is_string( $_POST['epm_details_action'] ) ? sanitize_key( wp_unslash( $_POST['epm_details_action'] ) ) : 'save';

		switch ( $do ) {
			case 'apply':
				$design->apply_suggested_details();
				$status = 'details-applied';
				break;
			case 'dismiss':
				$design->dismiss_suggested_details();
				$status = 'details-dismissed';
				break;
			case 'reset':
				$design->reset_details();
				$status = 'details-reset';
				break;
			default:
				// Checkboxes: present = shown. Every detail of every context
				// is in the form, so a missing one is unchecked.
				$posted    = isset( $_POST['epm_details'] ) && is_array( $_POST['epm_details'] ) ? wp_unslash( $_POST['epm_details'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to booleans below.
				$submitted = [];
				foreach ( Details::CONTEXTS as $context ) {
					foreach ( Details::flags( $context ) as $flag ) {
						$submitted[ $context ][ $flag ] = ! empty( $posted[ $context ][ $flag ] );
					}
				}
				$design->update_details( $submitted, true );
				$status = 'details-saved';
		}

		wp_safe_redirect( add_query_arg( 'epm_design', $status, admin_url( 'admin.php?page=epm-design' ) ) . '#epm-details' );
		exit;
	}

	/**
	 * Apply a preset from the Design screen (POST, then back to the screen).
	 *
	 * @return void
	 */
	public function handle_design_preset(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to change the design.', 'elementor-podcast-manager' ) );
		}

		check_admin_referer( 'epm_design_preset', '_epm_preset_nonce' );

		$preset_id = isset( $_POST['epm_preset'] ) && is_string( $_POST['epm_preset'] ) ? sanitize_key( wp_unslash( $_POST['epm_preset'] ) ) : '';
		$status    = ( '' !== $preset_id && epm()->design->apply_preset( $preset_id ) ) ? 'preset-applied' : 'preset-error';

		wp_safe_redirect( add_query_arg( 'epm_design', $status, admin_url( 'admin.php?page=epm-design' ) ) );
		exit;
	}

	/**
	 * Which --epm-* custom property every design token sets, and how its
	 * value is written. The same table drives design_css_vars() (preset
	 * swatches, the preview's first render) and the Design screen script
	 * (live preview), so the two cannot drift. It mirrors
	 * DesignSettings::output_tokens(); tests/integration/admin.php checks
	 * that both produce the same variables for every preset.
	 *
	 * Entry keys: var (custom property), kind (color|px|choice|layout),
	 * values (choice => CSS value, from the DesignSettings helpers),
	 * optional (an empty value prints nothing).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function design_token_map(): array {
		return [
			'accent'                 => [ 'var' => '--epm-accent', 'kind' => 'color' ],
			'on_accent'              => [ 'var' => '--epm-on-accent', 'kind' => 'color' ],
			'text'                   => [ 'var' => '--epm-text', 'kind' => 'color' ],
			'muted'                  => [ 'var' => '--epm-text-muted', 'kind' => 'color' ],
			'background'             => [ 'var' => '--epm-background', 'kind' => 'color' ],
			'surface'                => [ 'var' => '--epm-surface', 'kind' => 'color' ],
			'border_color'           => [ 'var' => '--epm-border', 'kind' => 'color' ],
			'track_color'            => [ 'var' => '--epm-track', 'kind' => 'color', 'optional' => true ],
			'border_radius'          => [ 'var' => '--epm-radius', 'kind' => 'px' ],
			'artwork_radius'         => [ 'var' => '--epm-artwork-radius', 'kind' => 'px' ],
			'spacing'                => [ 'var' => '--epm-gap', 'kind' => 'px' ],
			'title_font_size'        => [ 'var' => '--epm-title-size', 'kind' => 'px' ],
			'meta_font_size'         => [ 'var' => '--epm-meta-size', 'kind' => 'px' ],
			'button_shape'           => [ 'var' => '--epm-button-radius', 'kind' => 'choice', 'values' => DesignSettings::button_shapes(), 'fallback' => 'rounded' ],
			'shadow'                 => [ 'var' => '--epm-shadow', 'kind' => 'choice', 'values' => DesignSettings::shadows(), 'fallback' => 'none' ],
			'font_family'            => [ 'var' => '--epm-font', 'kind' => 'choice', 'values' => DesignSettings::font_stacks(), 'fallback' => 'inherit', 'optional' => true ],
			'default_player_layout'  => [ 'kind' => 'layout' ],
			'default_episode_layout' => [ 'kind' => 'layout' ],
		];
	}

	/**
	 * Extra variables for designs on a dark background (white image
	 * outline, lighter error red, section surface and padding): the same
	 * list DesignSettings::output_tokens() prints.
	 *
	 * @return array<string, string>
	 */
	public static function design_dark_vars(): array {
		return DesignSettings::dark_vars();
	}

	/**
	 * CSS custom properties for a set of design tokens.
	 *
	 * @param array<string, mixed> $tokens Sanitized tokens (DesignSettings::sanitize()).
	 * @return array<string, string> Property => value; empty values are left out.
	 */
	public static function design_css_vars( array $tokens ): array {
		$vars = [];

		foreach ( self::design_token_map() as $key => $entry ) {
			if ( empty( $entry['var'] ) ) {
				continue;
			}

			$value = (string) ( $tokens[ $key ] ?? '' );

			switch ( $entry['kind'] ) {
				case 'color':
					$css = (string) sanitize_hex_color( $value );
					break;
				case 'px':
					$css = '' === $value ? '' : absint( $value ) . 'px';
					break;
				case 'choice':
					$css = (string) ( $entry['values'][ $value ] ?? $entry['values'][ $entry['fallback'] ] ?? '' );
					break;
				default:
					$css = '';
			}

			if ( '' !== $css ) {
				$vars[ (string) $entry['var'] ] = $css;
			}
		}

		if ( DesignSettings::is_dark( (string) ( $tokens['background'] ?? '' ) ) ) {
			$vars = array_merge( $vars, self::design_dark_vars() );
		}

		return $vars;
	}

	/**
	 * Tokens of a preset as the option would hold them after applying it.
	 *
	 * @param array<string, mixed> $preset Preset (Presets::get()).
	 * @return array<string, mixed> Token key => value (design_export_keys()).
	 */
	public static function preset_values( array $preset ): array {
		$values = epm()->design->sanitize( array_merge( DesignSettings::defaults(), (array) ( $preset['tokens'] ?? [] ) ) );

		return array_intersect_key( $values, array_flip( self::design_export_keys() ) );
	}

	/**
	 * Data for admin/js/epm-design.js: the token table, the current values,
	 * every preset's values and the dark-background rule.
	 *
	 * @return array<string, mixed>
	 */
	public static function design_preview_config(): array {
		$design  = epm()->design;
		$presets = [];

		foreach ( epm()->presets->all() as $id => $preset ) {
			$presets[ (string) $id ] = [
				'name'    => (string) ( $preset['name'] ?? $id ),
				'values'  => self::preset_values( (array) $preset ),
				'details' => (object) Presets::details( (array) $preset ),
			];
		}

		$neutral = [];
		$labels  = [];
		$places  = [];
		foreach ( Details::CONTEXTS as $context ) {
			$neutral[ $context ] = Details::neutral( $context );
			$places[ $context ]  = Details::context_label( $context );
			foreach ( Details::flags( $context ) as $flag ) {
				$labels[ $flag ] = Details::label( $flag );
			}
		}

		$map = [];
		foreach ( self::design_token_map() as $key => $entry ) {
			$map[ $key ] = [
				'var'      => (string) ( $entry['var'] ?? '' ),
				'kind'     => (string) $entry['kind'],
				'values'   => (object) ( $entry['values'] ?? [] ),
				'fallback' => (string) ( $entry['fallback'] ?? '' ),
				'optional' => ! empty( $entry['optional'] ),
			];
		}

		return [
			'map'     => $map,
			'dark'    => [
				// Same threshold as DesignSettings::is_dark().
				'luminance' => 0.2,
				'vars'      => self::design_dark_vars(),
			],
			'values'  => array_intersect_key( $design->all(), array_flip( self::design_export_keys() ) ),
			'presets' => $presets,
			'active'  => (string) $design->get( 'preset' ),
			// Card layouts render cards; every other layout renders rows.
			'cardLayouts'     => [ 'cards', 'grid' ],
			'numberedLayouts' => [ 'list', 'editorial-rows' ],
			// Details shown by default: the built-in defaults per context,
			// the site's choices, and the names of details and contexts.
			'details'         => [
				'neutral' => $neutral,
				'site'    => (object) Details::sanitize_map( $design->get( 'details' ) ),
				'labels'  => $labels,
				'places'  => $places,
			],
			// The episode page turns Minimal/Compact into Full while one of
			// these is shown (EpisodeTemplate::player_args()).
			'episodePageFull' => [ 'show_playback_speed', 'show_volume', 'show_download', 'show_share', 'show_description' ],
		];
	}

	/**
	 * WCAG contrast ratio of two hex colors.
	 *
	 * @param string $first  Hex color.
	 * @param string $second Hex color.
	 * @return float Ratio from 1 to 21 (1 when a color is invalid).
	 */
	public static function contrast_ratio( string $first, string $second ): float {
		$luminance = static function ( string $hex ): ?float {
			$hex = ltrim( (string) sanitize_hex_color( $hex ), '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			if ( 6 !== strlen( $hex ) ) {
				return null;
			}
			$channel = static function ( string $pair ): float {
				$c = hexdec( $pair ) / 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			};
			return 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );
		};

		$a = $luminance( $first );
		$b = $luminance( $second );

		if ( null === $a || null === $b ) {
			return 1.0;
		}

		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/**
	 * Color pairs checked on the Design screen.
	 *
	 * @return array<int, array{fg: string, bg: string, min: float, label: string}>
	 */
	public static function contrast_pairs(): array {
		return [
			[
				'fg'    => 'text',
				'bg'    => 'background',
				'min'   => 4.5,
				'label' => __( 'Text on background', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'text',
				'bg'    => 'surface',
				'min'   => 4.5,
				'label' => __( 'Text on surface', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'muted',
				'bg'    => 'background',
				'min'   => 4.5,
				'label' => __( 'Muted text on background', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'muted',
				'bg'    => 'surface',
				'min'   => 4.5,
				'label' => __( 'Muted text on surface', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'accent',
				'bg'    => 'background',
				'min'   => 4.5,
				'label' => __( 'Accent text on background', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'on_accent',
				'bg'    => 'accent',
				'min'   => 4.5,
				'label' => __( 'Text on accent', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'track_color',
				'bg'    => 'background',
				'min'   => 3.0,
				'label' => __( 'Timeline track on background', 'elementor-podcast-manager' ),
			],
			[
				'fg'    => 'track_color',
				'bg'    => 'surface',
				'min'   => 3.0,
				'label' => __( 'Timeline track on surface', 'elementor-podcast-manager' ),
			],
		];
	}

	/**
	 * Episodes for the Design preview: the latest episode with audio for
	 * the player and the two newest episodes for the list, or neutral
	 * sample episodes on a site without any.
	 *
	 * @return array{player: array<string, mixed>, list: array<int, array<string, mixed>>, sample: bool}
	 */
	public static function design_preview_episodes(): array {
		$list = [];
		foreach ( epm()->episodes->get_episodes( [ 'posts_per_page' => 2 ] ) as $post ) {
			$data = epm()->episodes->get_data( $post );
			if ( $data ) {
				$list[] = $data;
			}
		}

		$latest = epm()->episodes->get_latest( true );
		$player = $latest ? epm()->episodes->get_data( $latest ) : null;
		$sample = false;

		if ( empty( $list ) ) {
			$sample = true;
			$list   = self::sample_episodes();
		}

		if ( null === $player ) {
			$player = $list[0];
		}

		// The preview never plays: a player needs audio to render.
		$player['has_audio'] = true;

		return [
			'player' => $player,
			'list'   => $list,
			'sample' => $sample,
		];
	}

	/**
	 * Neutral sample episodes for the Design preview.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sample_episodes(): array {
		$artwork = (int) epm()->settings->get( 'artwork_id' );
		$date    = wp_date( (string) get_option( 'date_format' ) );
		$base    = [
			'id'                => 0,
			'url'               => '#',
			'date'              => $date,
			'description'       => '',
			'audio_id'          => 0,
			'audio_url'         => '',
			'has_audio'         => true,
			'artwork_id'        => $artwork,
			'artwork_url'       => '',
			'season_number'     => '',
			'guest_role'        => '',
			'guest_image_id'    => 0,
			'chapters'          => [],
			'platform_urls'     => [],
			'transcript_files'  => [],
			'episode_type'      => 'full',
		];

		return [
			array_merge(
				$base,
				[
					'title'             => __( 'How we plan a new season', 'elementor-podcast-manager' ),
					'short_description' => __( 'A sample episode that shows your design. Publish an episode to preview it with your own content.', 'elementor-podcast-manager' ),
					'duration'          => '42:18',
					'duration_seconds'  => 2538,
					'episode_number'    => '12',
					/* translators: sample guest name shown in the Design preview */
					'guest_name'        => __( 'Jordan Lee', 'elementor-podcast-manager' ),
					'guest_company'     => '',
				]
			),
			array_merge(
				$base,
				[
					'title'             => __( 'Listener questions, answered', 'elementor-podcast-manager' ),
					'short_description' => __( 'The second sample episode, so the list shows more than one row.', 'elementor-podcast-manager' ),
					'duration'          => '36:05',
					'duration_seconds'  => 2165,
					'episode_number'    => '11',
					'guest_name'        => '',
					'guest_company'     => '',
				]
			),
		];
	}

	/**
	 * Subscribe links for the Design preview: the podcast's own platform
	 * links, or two common directories as samples.
	 *
	 * @return array<int, array{label: string, url: string, service: string}>
	 */
	public static function design_preview_links(): array {
		$links = array_slice( (array) epm()->settings->get( 'platform_links' ), 0, 3 );

		if ( ! empty( $links ) ) {
			return $links;
		}

		return [
			[
				'label'   => '',
				'url'     => 'https://example.com/',
				'service' => 'apple',
			],
			[
				'label'   => '',
				'url'     => 'https://example.com/',
				'service' => 'spotify',
			],
		];
	}

	/**
	 * Human label of a detail (show_volume → "Volume slider").
	 *
	 * @param string $flag Detail key, e.g. show_artwork.
	 * @return string
	 */
	public static function design_flag_label( string $flag ): string {
		return Details::label( $flag );
	}

	/**
	 * Episode list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function list_columns( array $columns ): array {
		$new = [];

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['epm_artwork'] = __( 'Artwork', 'elementor-podcast-manager' );
			}

			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['epm_episode_no'] = __( 'Episode #', 'elementor-podcast-manager' );
				$new['epm_guest']      = __( 'Guest', 'elementor-podcast-manager' );
				$new['epm_duration']   = __( 'Duration', 'elementor-podcast-manager' );
				$new['epm_audio']      = __( 'Audio', 'elementor-podcast-manager' );
			}
		}

		unset( $new['date'] );
		$new['date'] = __( 'Published', 'elementor-podcast-manager' );

		return $new;
	}

	/**
	 * Columns the episode list hides until the user changes Screen Options,
	 * so the title keeps room at laptop widths: Author, and Topics while no
	 * topic exists yet.
	 *
	 * @param array<int, string> $hidden Hidden column keys.
	 * @param \WP_Screen|mixed   $screen Current screen.
	 * @return array<int, string>
	 */
	public function default_hidden_columns( $hidden, $screen ): array {
		$hidden = is_array( $hidden ) ? $hidden : [];

		if ( ! $screen instanceof \WP_Screen || 'edit-' . EpisodePostType::CPT !== $screen->id ) {
			return $hidden;
		}

		$hidden[] = 'author';

		if ( taxonomy_exists( EpisodePostType::TOPIC ) ) {
			$count = wp_count_terms(
				[
					'taxonomy'   => EpisodePostType::TOPIC,
					'hide_empty' => false,
				]
			);
			if ( ! is_wp_error( $count ) && 0 === (int) $count ) {
				$hidden[] = 'taxonomy-' . EpisodePostType::TOPIC;
			}
		}

		return array_values( array_unique( $hidden ) );
	}

	/**
	 * Render custom list columns.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		$data = epm()->episodes->get_data( $post_id );

		if ( ! $data ) {
			return;
		}

		switch ( $column ) {
			case 'epm_artwork':
				if ( '' !== (string) $data['artwork_url'] ) {
					echo '<img src="' . esc_url( (string) $data['artwork_url'] ) . '" width="48" height="48" alt="" loading="lazy" decoding="async" />';
				} elseif ( (int) $data['artwork_id'] > 0 ) {
					echo wp_get_attachment_image( (int) $data['artwork_id'], [ 48, 48 ], false, [ 'loading' => 'lazy' ] );
				} else {
					echo '<span class="epm-list-empty" aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No artwork', 'elementor-podcast-manager' ) . '</span>';
				}
				break;

			case 'epm_episode_no':
				// Raw values for Quick Edit (admin/js/epm-admin.js).
				printf(
					'<span hidden class="epm-inline-data" id="epm-inline-%1$d" data-number="%2$s" data-season="%3$s" data-type="%4$s" data-explicit="%5$s"></span>',
					(int) $post_id,
					esc_attr( (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'episode_number', true ) ),
					esc_attr( (string) get_post_meta( $post_id, Episodes::META_PREFIX . 'season_number', true ) ),
					esc_attr( (string) ( get_post_meta( $post_id, Episodes::META_PREFIX . 'episode_type', true ) ?: 'full' ) ),
					esc_attr( (string) ( get_post_meta( $post_id, Episodes::META_PREFIX . 'explicit', true ) ?: 'inherit' ) )
				);
				echo esc_html( (string) $data['episode_number'] );
				if ( '' !== (string) $data['season_number'] ) {
					echo ' <span class="epm-list-muted"><span aria-hidden="true">';
					/* translators: %s: season number (abbreviated, e.g. "S2") */
					echo esc_html( sprintf( __( 'S%s', 'elementor-podcast-manager' ), (string) $data['season_number'] ) );
					echo '</span><span class="screen-reader-text">';
					/* translators: %s: season number */
					echo esc_html( sprintf( __( 'Season %s', 'elementor-podcast-manager' ), (string) $data['season_number'] ) );
					echo '</span></span>';
				}
				break;

			case 'epm_guest':
				echo esc_html( (string) $data['guest_name'] );
				break;

			case 'epm_duration':
				echo esc_html( (string) $data['duration'] );
				break;

			case 'epm_audio':
				$status = self::audio_status( $data );
				printf(
					'<span class="epm-status epm-status--%1$s"%2$s>%3$s</span>',
					esc_attr( $status['key'] ),
					'' !== $status['title'] ? ' title="' . esc_attr( $status['title'] ) . '"' : '',
					esc_html( $status['label'] )
				);
				if ( 'external' === $data['audio_source'] ) {
					echo '<br /><span class="epm-list-source"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>' . esc_html__( 'Audio URL', 'elementor-podcast-manager' ) . '</span>';
				}
				break;
		}
	}

	/**
	 * Sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( array $columns ): array {
		$columns['epm_episode_no'] = 'epm_episode_number';
		$columns['epm_duration']   = 'epm_duration';

		return $columns;
	}

	/**
	 * Episode type choices.
	 *
	 * @return array<string, string>
	 */
	public static function episode_types(): array {
		return [
			'full'    => __( 'Full', 'elementor-podcast-manager' ),
			'trailer' => __( 'Trailer', 'elementor-podcast-manager' ),
			'bonus'   => __( 'Bonus', 'elementor-podcast-manager' ),
		];
	}

	/**
	 * Explicit-content choices of an episode.
	 *
	 * @return array<string, string>
	 */
	public static function explicit_choices(): array {
		return [
			'inherit'  => __( 'Inherit podcast setting', 'elementor-podcast-manager' ),
			'clean'    => __( 'Clean', 'elementor-podcast-manager' ),
			'explicit' => __( 'Explicit', 'elementor-podcast-manager' ),
		];
	}

	/**
	 * Quick Edit fields: episode number, season, type, explicit. Printed
	 * once (for the episode number column); admin/js/epm-admin.js fills
	 * them from the row's .epm-inline-data element.
	 *
	 * @param string $column    Column key.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function quick_edit_box( $column, $post_type ): void {
		if ( 'epm_episode_no' !== $column || EpisodePostType::CPT !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right epm-inline-edit">
			<div class="inline-edit-col">
				<span class="title inline-edit-categories-label epm-inline-edit__legend"><?php esc_html_e( 'Episode information', 'elementor-podcast-manager' ); ?></span>
				<?php wp_nonce_field( 'epm_quick_edit', 'epm_quick_nonce', false ); ?>
				<div class="epm-inline-edit__grid">
					<label>
						<span class="title"><?php esc_html_e( 'Episode #', 'elementor-podcast-manager' ); ?></span>
						<span class="input-text-wrap"><input type="number" name="epm_quick[episode_number]" value="" min="0" step="1" inputmode="numeric" data-epm-quick="number" /></span>
					</label>
					<label>
						<span class="title"><?php esc_html_e( 'Season', 'elementor-podcast-manager' ); ?></span>
						<span class="input-text-wrap"><input type="number" name="epm_quick[season_number]" value="" min="0" step="1" inputmode="numeric" data-epm-quick="season" /></span>
					</label>
					<label>
						<span class="title"><?php esc_html_e( 'Type', 'elementor-podcast-manager' ); ?></span>
						<select name="epm_quick[episode_type]" data-epm-quick="type">
							<?php foreach ( self::episode_types() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span class="title"><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></span>
						<select name="epm_quick[explicit]" data-epm-quick="explicit">
							<?php foreach ( self::explicit_choices() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Bulk Edit fields. Empty values and "— No change —" leave episodes as
	 * they are. "Number from" numbers the selected episodes in publishing
	 * order (oldest first), starting at the given number.
	 *
	 * @param string $column    Column key.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function bulk_edit_box( $column, $post_type ): void {
		if ( 'epm_episode_no' !== $column || EpisodePostType::CPT !== $post_type ) {
			return;
		}

		$no_change = __( '— No change —', 'elementor-podcast-manager' );
		?>
		<fieldset class="inline-edit-col-right epm-inline-edit">
			<div class="inline-edit-col">
				<span class="title inline-edit-categories-label epm-inline-edit__legend"><?php esc_html_e( 'Episode information', 'elementor-podcast-manager' ); ?></span>
				<?php wp_nonce_field( 'epm_bulk_edit', 'epm_bulk_nonce', false ); ?>
				<div class="epm-inline-edit__grid">
					<label>
						<span class="title"><?php esc_html_e( 'Number from', 'elementor-podcast-manager' ); ?></span>
						<span class="input-text-wrap"><input type="number" name="epm_bulk[number_from]" value="" min="0" step="1" inputmode="numeric" aria-describedby="epm-bulk-number-help" /></span>
					</label>
					<label>
						<span class="title"><?php esc_html_e( 'Season', 'elementor-podcast-manager' ); ?></span>
						<span class="input-text-wrap"><input type="number" name="epm_bulk[season_number]" value="" min="0" step="1" inputmode="numeric" /></span>
					</label>
					<label>
						<span class="title"><?php esc_html_e( 'Type', 'elementor-podcast-manager' ); ?></span>
						<select name="epm_bulk[episode_type]">
							<option value=""><?php echo esc_html( $no_change ); ?></option>
							<?php foreach ( self::episode_types() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span class="title"><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></span>
						<select name="epm_bulk[explicit]">
							<option value=""><?php echo esc_html( $no_change ); ?></option>
							<?php foreach ( self::explicit_choices() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<p class="description epm-inline-edit__help" id="epm-bulk-number-help"><?php esc_html_e( 'Leave a field empty to keep each episode’s value. “Number from” numbers the selected episodes by publish date, oldest first.', 'elementor-podcast-manager' ); ?></p>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Save Quick Edit and Bulk Edit fields of one episode.
	 *
	 * Runs on save_post for episodes and only acts on its own fields with
	 * their own nonce (the editor's meta boxes save through EpisodeMeta).
	 * The user must be able to edit the episode.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function save_inline_edit( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- verified below.
		if ( isset( $_POST['epm_quick_nonce'], $_POST['epm_quick'] ) && is_array( $_POST['epm_quick'] ) ) {
			if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['epm_quick_nonce'] ) ), 'epm_quick_edit' ) ) {
				self::apply_inline_values( $post_id, wp_unslash( $_POST['epm_quick'] ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field.
			}
			return;
		}

		if ( isset( $_REQUEST['epm_bulk_nonce'], $_REQUEST['epm_bulk'] ) && is_array( $_REQUEST['epm_bulk'] ) ) {
			if ( wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['epm_bulk_nonce'] ) ), 'epm_bulk_edit' ) ) {
				$values = wp_unslash( $_REQUEST['epm_bulk'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field.
				$ids    = isset( $_REQUEST['post'] ) ? array_map( 'absint', (array) $_REQUEST['post'] ) : [ $post_id ];

				$from = trim( (string) ( $values['number_from'] ?? '' ) );
				if ( '' !== $from && ctype_digit( $from ) ) {
					$values['episode_number'] = (string) ( (int) $from + self::bulk_position( $post_id, $ids ) );
				}
				unset( $values['number_from'] );

				self::apply_inline_values( $post_id, (array) $values, true );
			}
		}
		// phpcs:enable
	}

	/**
	 * Position of an episode among the bulk-edited ones, oldest first.
	 *
	 * @param int   $post_id Post ID.
	 * @param int[] $ids     Selected post IDs.
	 * @return int 0-based position.
	 */
	public static function bulk_position( int $post_id, array $ids ): int {
		static $order = [];

		$key = implode( ',', $ids );
		if ( ! isset( $order[ $key ] ) ) {
			$posts = get_posts(
				[
					'post_type'      => EpisodePostType::CPT,
					'post__in'       => $ids ? $ids : [ 0 ],
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => [
						'date' => 'ASC',
						'ID'   => 'ASC',
					],
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			$order[ $key ] = array_flip( array_map( 'intval', $posts ) );
		}

		return (int) ( $order[ $key ][ $post_id ] ?? 0 );
	}

	/**
	 * Write Quick Edit / Bulk Edit values of one episode.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $values  Raw values: episode_number, season_number, episode_type, explicit.
	 * @param bool                 $bulk    Bulk edit: empty values mean "no change".
	 * @return void
	 */
	public static function apply_inline_values( int $post_id, array $values, bool $bulk ): void {
		$p = Episodes::META_PREFIX;

		foreach ( [ 'episode_number', 'season_number' ] as $key ) {
			if ( ! array_key_exists( $key, $values ) || ! is_scalar( $values[ $key ] ) ) {
				continue;
			}
			$raw = trim( (string) $values[ $key ] );
			if ( '' === $raw ) {
				if ( ! $bulk ) {
					update_post_meta( $post_id, $p . $key, '' );
				}
				continue;
			}
			if ( ctype_digit( $raw ) ) {
				update_post_meta( $post_id, $p . $key, absint( $raw ) );
			}
		}

		$choices = [
			'episode_type' => array_keys( self::episode_types() ),
			'explicit'     => array_keys( self::explicit_choices() ),
		];
		foreach ( $choices as $key => $allowed ) {
			$raw = isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? sanitize_key( (string) $values[ $key ] ) : '';
			if ( in_array( $raw, $allowed, true ) ) {
				update_post_meta( $post_id, $p . $key, $raw );
			}
		}

		Episodes::clear_data_cache( $post_id );
	}
}
