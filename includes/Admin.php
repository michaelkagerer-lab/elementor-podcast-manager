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
		add_action( 'manage_' . EpisodePostType::CPT . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-' . EpisodePostType::CPT . '_sortable_columns', [ $this, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_admin_orderby' ] );
		add_filter( 'parent_file', [ $this, 'menu_highlight' ] );
		add_filter( 'post_updated_messages', [ $this, 'updated_messages' ] );
		add_filter( 'bulk_post_updated_messages', [ $this, 'bulk_updated_messages' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_epm_design_export', [ $this, 'handle_design_export' ] );
		add_action( 'admin_post_epm_design_import', [ $this, 'handle_design_import' ] );

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
		/* translators: %s: number of episodes */
		$bulk_messages[ EpisodePostType::CPT ] = [
			'updated'   => _n( '%s episode updated.', '%s episodes updated.', $bulk_counts['updated'], 'elementor-podcast-manager' ),
			'locked'    => _n( '%s episode not updated, somebody is editing it.', '%s episodes not updated, somebody is editing them.', $bulk_counts['locked'], 'elementor-podcast-manager' ),
			'deleted'   => _n( '%s episode permanently deleted.', '%s episodes permanently deleted.', $bulk_counts['deleted'], 'elementor-podcast-manager' ),
			'trashed'   => _n( '%s episode moved to the Trash.', '%s episodes moved to the Trash.', $bulk_counts['trashed'], 'elementor-podcast-manager' ),
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

		if ( $screen && EpisodePostType::CPT === $screen->post_type ) {
			return 'epm-dashboard';
		}

		return $parent_file;
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
		wp_enqueue_style( 'epm-admin', EPM_URL . 'admin/css/epm-admin.css', [], EPM_VERSION );
		wp_enqueue_script( 'epm-admin', EPM_URL . 'admin/js/epm-admin.js', [ 'jquery' ], EPM_VERSION, true );

		wp_localize_script(
			'epm-admin',
			'epmAdmin',
			[
				'nonce'   => wp_create_nonce( 'epm_episode_meta' ),
				'choose'  => __( 'Choose audio file', 'elementor-podcast-manager' ),
				'use'     => __( 'Use this audio', 'elementor-podcast-manager' ),
				'strings' => [
					'uploadAudio'    => __( 'Upload Episode Audio', 'elementor-podcast-manager' ),
					'replaceAudio'   => __( 'Replace Audio', 'elementor-podcast-manager' ),
					'remove'         => __( 'Remove', 'elementor-podcast-manager' ),
					'dropHint'       => __( 'Drop an MP3 or M4A file here, or', 'elementor-podcast-manager' ),
					'uploading'      => __( 'Uploading…', 'elementor-podcast-manager' ),
					'uploadFailed'   => __( 'Upload failed. Please try again.', 'elementor-podcast-manager' ),
					'describeFailed' => __( 'Could not read the audio file details. Please try again.', 'elementor-podcast-manager' ),
					'invalidType'    => __( 'Please drop an MP3, M4A or WAV file.', 'elementor-podcast-manager' ),
					'wavWarning'     => __( 'WAV is for internal storage only and will be excluded from the podcast feed.', 'elementor-podcast-manager' ),
				],
			]
		);
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
			__( 'Add Episode', 'elementor-podcast-manager' ),
			__( 'Add Episode', 'elementor-podcast-manager' ),
			Capabilities::manage_episodes(),
			'post-new.php?post_type=' . EpisodePostType::CPT
		);

		add_submenu_page(
			'epm-dashboard',
			__( 'Podcast Settings', 'elementor-podcast-manager' ),
			__( 'Podcast Settings', 'elementor-podcast-manager' ),
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

		$settings     = epm()->settings;
		$latest       = epm()->episodes->get_latest();
		$latest_data  = $latest ? epm()->episodes->get_data( $latest ) : null;
		$count        = epm()->episodes->count_published();
		$artwork      = $settings->artwork_url( 'medium' );

		require EPM_PATH . 'admin/views/dashboard.php';
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
		$services = [
			'spotify' => __( 'Spotify', 'elementor-podcast-manager' ),
			'apple'   => __( 'Apple Podcasts', 'elementor-podcast-manager' ),
			'youtube' => __( 'YouTube', 'elementor-podcast-manager' ),
			'amazon'  => __( 'Amazon Music', 'elementor-podcast-manager' ),
			'rss'     => __( 'RSS', 'elementor-podcast-manager' ),
			'custom'  => __( 'Custom', 'elementor-podcast-manager' ),
		];
		?>
		<div class="epm-repeat" data-epm-repeat="links">
			<div class="epm-repeat__rows" data-epm-repeat-rows>
				<?php foreach ( array_values( $links ) as $index => $link ) : ?>
					<div class="epm-repeat__row epm-repeat__row--links" data-epm-repeat-row>
					<span class="epm-repeat__order">
						<button type="button" class="button-link" data-epm-repeat-up aria-label="<?php esc_attr_e( 'Move link up', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move up', 'elementor-podcast-manager' ); ?>">▲</button>
						<button type="button" class="button-link" data-epm-repeat-down aria-label="<?php esc_attr_e( 'Move link down', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move down', 'elementor-podcast-manager' ); ?>">▼</button>
					</span>
						<select name="epm_podcast_settings[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][service]" aria-label="<?php esc_attr_e( 'Service', 'elementor-podcast-manager' ); ?>">
							<?php foreach ( $services as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $link['service'] ?? 'custom', $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="epm_podcast_settings[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $link['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Label', 'elementor-podcast-manager' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'elementor-podcast-manager' ); ?>" />
						<input type="url" name="epm_podcast_settings[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( $link['url'] ?? '' ); ?>" placeholder="https://" class="widefat" aria-label="<?php esc_attr_e( 'URL', 'elementor-podcast-manager' ); ?>" />
						<button type="button" class="button-link epm-repeat__remove" data-epm-repeat-remove aria-label="<?php esc_attr_e( 'Remove link', 'elementor-podcast-manager' ); ?>">×</button>
					</div>
				<?php endforeach; ?>
			</div>
			<template data-epm-repeat-template>
				<div class="epm-repeat__row epm-repeat__row--links" data-epm-repeat-row>
					<span class="epm-repeat__order">
						<button type="button" class="button-link" data-epm-repeat-up aria-label="<?php esc_attr_e( 'Move link up', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move up', 'elementor-podcast-manager' ); ?>">▲</button>
						<button type="button" class="button-link" data-epm-repeat-down aria-label="<?php esc_attr_e( 'Move link down', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move down', 'elementor-podcast-manager' ); ?>">▼</button>
					</span>
					<select name="epm_podcast_settings[<?php echo esc_attr( $key ); ?>][__INDEX__][service]" aria-label="<?php esc_attr_e( 'Service', 'elementor-podcast-manager' ); ?>">
						<?php foreach ( $services as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="epm_podcast_settings[<?php echo esc_attr( $key ); ?>][__INDEX__][label]" value="" placeholder="<?php esc_attr_e( 'Label', 'elementor-podcast-manager' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'elementor-podcast-manager' ); ?>" />
					<input type="url" name="epm_podcast_settings[<?php echo esc_attr( $key ); ?>][__INDEX__][url]" value="" placeholder="https://" class="widefat" aria-label="<?php esc_attr_e( 'URL', 'elementor-podcast-manager' ); ?>" />
					<button type="button" class="button-link epm-repeat__remove" data-epm-repeat-remove aria-label="<?php esc_attr_e( 'Remove link', 'elementor-podcast-manager' ); ?>">×</button>
				</div>
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
	 * Render a media-library image picker field for the settings screens.
	 *
	 * @param string $key Setting key within epm_podcast_settings / epm_design_settings.
	 * @param int    $attachment_id Current attachment ID.
	 * @param string $button_label Choose-button label.
	 * @param string $option_name Option array name.
	 * @return void
	 */
	public function media_field( string $key, int $attachment_id, string $button_label, string $option_name = 'epm_podcast_settings' ): void {
		wp_enqueue_media();
		$preview = $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, 'thumbnail', false, [ 'class' => 'epm-media-preview__img' ] ) : '';
		?>
		<div class="epm-media" data-epm-media="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $attachment_id ); ?>" data-epm-media-id />
			<div class="epm-media-preview" data-epm-media-preview><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<p>
				<button type="button" class="button" data-epm-media-choose data-title="<?php echo esc_attr( $button_label ); ?>">
					<?php echo esc_html( $button_label ); ?>
				</button>
				<button type="button" class="button-link" data-epm-media-remove>
					<?php esc_html_e( 'Remove', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
		</div>
		<?php
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
	 * Export the effective client design as a versioned JSON download.
	 *
	 * EXPORT ALLOWLIST — the design option holds visual tokens ONLY:
	 * colors, sizes, layout names, the preset id, and the preset's
	 * visibility/player/episodeList flag maps. It never holds attachment
	 * IDs, post IDs, URLs, or content (artwork and links live in the
	 * podcast settings option, which is deliberately NOT exported).
	 * The allowlist below is the complete set of exported keys, so no
	 * environment-specific value can leak into a design file.
	 *
	 * @return void
	 */
	public function handle_design_export(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to export the design.', 'elementor-podcast-manager' ) );
		}

		check_admin_referer( 'epm_design_export' );

		$design = epm()->design;

		// Explicit allowlist: scalar visual tokens only.
		$token_keys = [
			'accent',
			'on_accent',
			'text',
			'muted',
			'background',
			'surface',
			'border_color',
			'border_radius',
			'artwork_radius',
			'spacing',
			'default_player_layout',
			'default_episode_layout',
			'title_font_size',
			'meta_font_size',
		];

		$tokens = [];
		foreach ( $token_keys as $key ) {
			$tokens[ $key ] = $design->get( $key );
		}

		$payload = [
			'format'   => 'epm-design',
			'version'  => DesignSettings::EXPORT_VERSION,
			'exported' => gmdate( 'c' ),
			'preset'   => (string) $design->get( 'preset' ),
			'design'   => $tokens,
			'preset_extras' => [
				'visibility'  => $design->get( 'preset_visibility' ),
				'player'      => $design->get( 'preset_player' ),
				'episodeList' => $design->get( 'preset_episode_list' ),
			],
		];

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
	 * must be an array. Every value then passes through
	 * DesignSettings::sanitize(), which drops unknown keys, validates
	 * colors/numbers/layouts, and falls back to the stored preset id when
	 * the payload names an unregistered preset.
	 *
	 * @return void
	 */
	public function handle_design_import(): void {
		if ( ! Capabilities::can_manage_podcast() ) {
			wp_die( esc_html__( 'You do not have permission to import a design.', 'elementor-podcast-manager' ) );
		}

		check_admin_referer( 'epm_design_import' );

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
			$fail( __( 'No file was uploaded.', 'elementor-podcast-manager' ) );
		}

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			$fail( __( 'The file upload failed. Please try again.', 'elementor-podcast-manager' ) );
		}

		$type = wp_check_filetype( (string) ( $file['name'] ?? '' ), [ 'json' => 'application/json' ] );
		if ( 'json' !== $type['ext'] ) {
			$fail( __( 'Please upload a JSON design file (.json).', 'elementor-podcast-manager' ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$fail( __( 'The uploaded file could not be read.', 'elementor-podcast-manager' ) );
		}

		$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$fail( __( 'The uploaded file is empty.', 'elementor-podcast-manager' ) );
		}

		if ( strlen( $raw ) > 256 * 1024 ) {
			$fail( __( 'The file is too large to be a design export.', 'elementor-podcast-manager' ) );
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$fail( __( 'The file is not valid JSON.', 'elementor-podcast-manager' ) );
		}

		if ( ( $data['format'] ?? '' ) !== 'epm-design' ) {
			$fail( __( 'This file is not an Elementor Podcast Manager design export.', 'elementor-podcast-manager' ) );
		}

		$version = $data['version'] ?? 0;
		if ( ! is_int( $version ) || $version < 1 ) {
			$fail( __( 'The design file has no usable version number.', 'elementor-podcast-manager' ) );
		}
		if ( $version > DesignSettings::EXPORT_VERSION ) {
			$fail(
				sprintf(
					/* translators: %d: file version */
					__( 'This design was exported with a newer format version (%d) and cannot be imported by this plugin version.', 'elementor-podcast-manager' ),
					$version
				)
			);
		}

		if ( ! is_array( $data['design'] ?? null ) ) {
			$fail( __( 'The design file contains no design values.', 'elementor-podcast-manager' ) );
		}

		$design = epm()->design;

		$input            = $data['design'];
		$input['preset']  = (string) ( $data['preset'] ?? '' );
		$sanitized        = $design->sanitize( $input );

		// Preset behavior maps: sanitized the same way as on apply.
		// Keys missing from the payload keep their stored values.
		$extras    = is_array( $data['preset_extras'] ?? null ) ? $data['preset_extras'] : [];
		$extras_map = [
			'visibility'  => 'preset_visibility',
			'player'      => 'preset_player',
			'episodeList' => 'preset_episode_list',
		];
		foreach ( $extras_map as $payload_key => $option_key ) {
			if ( array_key_exists( $payload_key, $extras ) ) {
				$sanitized[ $option_key ] = $design->sanitize_flag_map( $extras[ $payload_key ] );
			}
		}

		update_option( DesignSettings::OPTION, $sanitized );

		wp_safe_redirect(
			add_query_arg( 'epm_design', 'imported', admin_url( 'admin.php?page=epm-design' ) )
		);
		exit;
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
				if ( (int) $data['artwork_id'] > 0 ) {
					echo wp_get_attachment_image( (int) $data['artwork_id'], [ 48, 48 ] );
				} else {
					echo '<span class="epm-list-empty">—</span>';
				}
				break;

			case 'epm_episode_no':
				echo esc_html( (string) $data['episode_number'] );
				if ( '' !== (string) $data['season_number'] ) {
					echo ' <span class="epm-list-muted">S' . esc_html( (string) $data['season_number'] ) . '</span>';
				}
				break;

			case 'epm_guest':
				echo esc_html( (string) $data['guest_name'] );
				break;

			case 'epm_duration':
				echo esc_html( (string) $data['duration'] );
				break;

			case 'epm_audio':
				if ( ! $data['has_audio'] ) {
					echo '<span class="epm-status epm-status--missing">' . esc_html__( 'Missing Audio', 'elementor-podcast-manager' ) . '</span>';
				} elseif ( ! AudioMetadata::is_distribution_format( (string) $data['audio_mime'] ) ) {
					echo '<span class="epm-status epm-status--warning" title="' . esc_attr__( 'Only MP3 and M4A audio is included in the podcast feed.', 'elementor-podcast-manager' ) . '">' . esc_html__( 'Not in feed', 'elementor-podcast-manager' ) . '</span>';
				} else {
					echo '<span class="epm-status epm-status--ready">' . esc_html__( 'Ready', 'elementor-podcast-manager' ) . '</span>';
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
}
