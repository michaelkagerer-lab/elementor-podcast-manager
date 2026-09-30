<?php
/**
 * Episode editing UI (Layer 1 admin).
 *
 * Groups episode data into focused meta boxes so publishing an episode
 * stays simple: title, audio, description, optional artwork, publish.
 * No design controls live here — those belong to Elementor.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EpisodeMeta {

	/**
	 * Queued admin notices for the episode editor.
	 *
	 * @var array<int, array{message: string, type: string}>
	 */
	private static array $notices = [];

	/**
	 * Wire meta boxes and saving.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes_' . EpisodePostType::CPT, [ $this, 'register_boxes' ] );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'use_block_editor' ], 10, 2 );
		add_action( 'edit_form_after_title', [ $this, 'render_after_title' ] );
		add_action( 'save_post_' . EpisodePostType::CPT, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_epm_upload_audio', [ $this, 'ajax_upload_audio' ] );
		add_action( 'wp_ajax_epm_audio_describe', [ $this, 'ajax_audio_describe' ] );
		add_action( 'wp_ajax_epm_episode_search', [ $this, 'ajax_episode_search' ] );
		add_action( 'admin_notices', [ self::class, 'render_notices' ] );
	}

	/**
	 * Queue an admin notice for the episode editor.
	 *
	 * @param string $message Notice text.
	 * @param string $type Notice type: error, warning, success, info.
	 * @return void
	 */
	public static function add_notice( string $message, string $type = 'info' ): void {
		self::$notices[] = [
			'message' => $message,
			'type'    => $type,
		];
	}

	/**
	 * Render queued notices (in-request and persisted across the save redirect).
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || EpisodePostType::CPT !== $screen->post_type ) {
			return;
		}

		$notices = self::$notices;

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post_id > 0 ) {
			$key   = self::notice_transient_key( $post_id );
			$saved = get_transient( $key );
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				$notices = array_merge( $notices, $saved );
				delete_transient( $key );
			}
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Transient key for cross-redirect notices.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function notice_transient_key( int $post_id ): string {
		return 'epm_notices_' . get_current_user_id() . '_' . $post_id;
	}

	/**
	 * Persist queued notices across the post-save redirect.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function persist_notices( int $post_id ): void {
		if ( ! empty( self::$notices ) ) {
			set_transient( self::notice_transient_key( $post_id ), self::$notices, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Enqueue admin assets for the episode editor.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		$screen = get_current_screen();

		if ( ! $screen || EpisodePostType::CPT !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'epm-admin',
			EPM_URL . 'admin/css/epm-admin.css',
			[],
			EPM_VERSION
		);

		wp_enqueue_script(
			'epm-admin',
			EPM_URL . 'admin/js/epm-admin.js',
			[ 'jquery' ],
			EPM_VERSION,
			true
		);

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
	 * Episodes use the classic editing screen by default.
	 *
	 * The publishing workflow is title → audio → description → publish. In
	 * the block editor every episode field lives in the collapsed "Meta
	 * Boxes" drawer below the canvas, which hides the audio upload. Sites
	 * that prefer the block editor can return true from
	 * epm_use_block_editor.
	 *
	 * @param bool   $use_block_editor Core decision.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function use_block_editor( $use_block_editor, $post_type ): bool {
		if ( EpisodePostType::CPT !== $post_type ) {
			return (bool) $use_block_editor;
		}

		return (bool) apply_filters( 'epm_use_block_editor', false );
	}

	/**
	 * Whether the classic screen renders this episode.
	 *
	 * @param \WP_Post|null $post Episode.
	 * @return bool
	 */
	private function is_classic_screen( $post ): bool {
		return ! ( $post instanceof \WP_Post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) );
	}

	/**
	 * Classic screen: render the audio box directly below the title, then
	 * label the content editor as the episode description.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_after_title( $post ): void {
		if ( ! $post instanceof \WP_Post || EpisodePostType::CPT !== $post->post_type ) {
			return;
		}

		echo '<div class="epm-after-title">';
		do_meta_boxes( get_current_screen(), 'epm_after_title', $post );
		echo '<h2 class="epm-description-heading">' . esc_html__( 'Episode description', 'elementor-podcast-manager' ) . '</h2>';
		echo '</div>';
	}

	/**
	 * Register grouped meta boxes.
	 *
	 * @param \WP_Post|null $post Post being edited.
	 * @return void
	 */
	public function register_boxes( $post = null ): void {
		add_meta_box(
			'epm-audio',
			__( 'Episode Audio', 'elementor-podcast-manager' ),
			[ $this, 'box_audio' ],
			EpisodePostType::CPT,
			$this->is_classic_screen( $post ) ? 'epm_after_title' : 'normal',
			'high'
		);

		add_meta_box(
			'epm-artwork',
			__( 'Artwork', 'elementor-podcast-manager' ),
			[ $this, 'box_artwork' ],
			EpisodePostType::CPT,
			'side',
			'default'
		);

		add_meta_box(
			'epm-details',
			__( 'Episode Information', 'elementor-podcast-manager' ),
			[ $this, 'box_details' ],
			EpisodePostType::CPT,
			'side',
			'default'
		);

		add_meta_box(
			'epm-guest',
			__( 'Guest', 'elementor-podcast-manager' ),
			[ $this, 'box_guest' ],
			EpisodePostType::CPT,
			'normal',
			'default'
		);

		add_meta_box(
			'epm-notes',
			__( 'Description & Show Notes', 'elementor-podcast-manager' ),
			[ $this, 'box_notes' ],
			EpisodePostType::CPT,
			'normal',
			'default'
		);

		add_meta_box(
			'epm-chapters',
			__( 'Chapters', 'elementor-podcast-manager' ),
			[ $this, 'box_chapters' ],
			EpisodePostType::CPT,
			'normal',
			'default'
		);

		add_meta_box(
			'epm-transcript',
			__( 'Transcript', 'elementor-podcast-manager' ),
			[ $this, 'box_transcript' ],
			EpisodePostType::CPT,
			'normal',
			'low'
		);

		add_meta_box(
			'epm-platforms',
			__( 'Platform Links (this episode)', 'elementor-podcast-manager' ),
			[ $this, 'box_platforms' ],
			EpisodePostType::CPT,
			'side',
			'default'
		);
	}

	/**
	 * Get a meta value for the current post in a box callback.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $key  Meta key without prefix.
	 * @param mixed    $default Default value.
	 * @return mixed
	 */
	private function meta( \WP_Post $post, string $key, $default = '' ) {
		$value = get_post_meta( $post->ID, Episodes::META_PREFIX . $key, true );

		return '' === $value ? $default : $value;
	}

	/**
	 * Nonce field shared by all boxes.
	 *
	 * @return void
	 */
	private function nonce(): void {
		wp_nonce_field( 'epm_episode_meta', 'epm_episode_meta_nonce' );
	}

	/**
	 * Audio box: prominent upload area.
	 *
	 * Selection and upload update the box in place via JS — no page reload,
	 * so unsaved title/description/fields are preserved. The attachment ID
	 * persists through the normal secured save_post workflow.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_audio( \WP_Post $post ): void {
		$this->nonce();

		$audio_id = (int) $this->meta( $post, 'audio_id', 0 );
		$duration = (string) $this->meta( $post, 'duration', '' );
		?>
		<div class="epm-upload" data-epm-upload="audio" data-epm-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<input type="hidden" name="epm[audio_id]" value="<?php echo esc_attr( (string) $audio_id ); ?>" data-epm-audio-id />
			<div class="epm-upload__drop" data-epm-drop>
				<div data-epm-audio-state>
					<?php $this->audio_state_html( $post, $audio_id ); ?>
				</div>
				<p class="epm-upload__actions">
					<button type="button" class="button button-primary button-large" data-epm-choose-audio>
						<?php echo $audio_id > 0 ? esc_html__( 'Replace Audio', 'elementor-podcast-manager' ) : esc_html__( 'Upload Episode Audio', 'elementor-podcast-manager' ); ?>
					</button>
					<?php if ( $audio_id > 0 ) : ?>
						<button type="button" class="button" data-epm-remove-audio>
							<?php esc_html_e( 'Remove', 'elementor-podcast-manager' ); ?>
						</button>
					<?php endif; ?>
				</p>
			</div>
			<div class="epm-upload__progress" data-epm-progress hidden>
				<div class="epm-upload__bar" data-epm-progress-bar></div>
				<span class="epm-upload__progress-label" data-epm-progress-label></span>
			</div>
			<div class="epm-upload__error notice notice-error inline" data-epm-error hidden></div>
			<p class="description">
				<?php esc_html_e( 'MP3 or M4A recommended for distribution. WAV is accepted for internal storage but excluded from the podcast feed. Duration and file size are detected automatically.', 'elementor-podcast-manager' ); ?>
			</p>
			<p>
				<label for="epm-duration"><?php esc_html_e( 'Duration (manual fallback)', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-duration" name="epm[duration]" value="<?php echo esc_attr( $duration ); ?>" placeholder="48:12" class="regular-text" />
				<span class="description"><?php esc_html_e( 'Format: minutes:seconds or hours:minutes:seconds.', 'elementor-podcast-manager' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the current audio state (file info + preview, or empty hint).
	 *
	 * @param \WP_Post $post Post object.
	 * @param int      $audio_id Attachment ID.
	 * @return void
	 */
	private function audio_state_html( \WP_Post $post, int $audio_id ): void {
		$audio_url = $audio_id > 0 ? wp_get_attachment_url( $audio_id ) : '';

		if ( ! $audio_url ) {
			echo '<p class="epm-upload__hint">' . esc_html__( 'Drop an MP3 or M4A file here, or', 'elementor-podcast-manager' ) . '</p>';
			return;
		}

		$duration = (string) $this->meta( $post, 'duration', '' );
		$size     = (int) $this->meta( $post, 'audio_size', 0 );
		$mime     = (string) get_post_mime_type( $audio_id );
		$readonly = ! AudioMetadata::is_distribution_format( $mime );
		?>
		<p class="epm-upload__file">
			<strong><?php echo esc_html( basename( (string) get_attached_file( $audio_id ) ) ); ?></strong><br />
			<span class="epm-upload__meta">
				<?php echo esc_html( $duration ); ?>
				<?php echo $size > 0 ? esc_html( size_format( $size ) ) : ''; ?>
				<?php echo esc_html( $mime ); ?>
			</span>
			<?php if ( $readonly ) : ?>
				<br /><span class="epm-upload__warning"><?php esc_html_e( 'WAV is for internal storage only and will be excluded from the podcast feed.', 'elementor-podcast-manager' ); ?></span>
			<?php endif; ?>
		</p>
		<audio controls preload="none" src="<?php echo esc_url( $audio_url ); ?>" class="epm-upload__preview"></audio>
		<?php
	}

	/**
	 * Artwork box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_artwork( \WP_Post $post ): void {
		$artwork_id = (int) $this->meta( $post, 'artwork_id', 0 );
		$preview    = $artwork_id > 0 ? wp_get_attachment_image( $artwork_id, 'medium', false, [ 'class' => 'epm-media-preview__img' ] ) : '';
		?>
		<div class="epm-media" data-epm-media="artwork">
			<input type="hidden" name="epm[artwork_id]" value="<?php echo esc_attr( (string) $artwork_id ); ?>" data-epm-media-id />
			<div class="epm-media-preview" data-epm-media-preview><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<p>
				<button type="button" class="button" data-epm-media-choose data-title="<?php esc_attr_e( 'Choose episode artwork', 'elementor-podcast-manager' ); ?>">
					<?php esc_html_e( 'Choose image', 'elementor-podcast-manager' ); ?>
				</button>
				<button type="button" class="button-link" data-epm-media-remove>
					<?php esc_html_e( 'Remove', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
			<p class="description"><?php esc_html_e( 'Optional square image (1400–3000 px, JPEG or PNG). Falls back to the featured image, then the podcast artwork.', 'elementor-podcast-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Episode information box: numbers, type, explicit.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_details( \WP_Post $post ): void {
		$episode_number = $this->meta( $post, 'episode_number', '' );
		$season_number  = $this->meta( $post, 'season_number', '' );
		$episode_type   = $this->meta( $post, 'episode_type', 'full' );
		$explicit       = $this->meta( $post, 'explicit', 'inherit' );
		?>
		<p>
			<label for="epm-episode-number"><?php esc_html_e( 'Episode number', 'elementor-podcast-manager' ); ?></label><br />
			<input type="number" id="epm-episode-number" name="epm[episode_number]" value="<?php echo esc_attr( (string) $episode_number ); ?>" class="small-text" min="0" step="1" />
		</p>
		<p>
			<label for="epm-season-number"><?php esc_html_e( 'Season number', 'elementor-podcast-manager' ); ?></label><br />
			<input type="number" id="epm-season-number" name="epm[season_number]" value="<?php echo esc_attr( (string) $season_number ); ?>" class="small-text" min="0" step="1" />
		</p>
		<p>
			<label for="epm-episode-type"><?php esc_html_e( 'Episode type', 'elementor-podcast-manager' ); ?></label><br />
			<select id="epm-episode-type" name="epm[episode_type]">
				<?php foreach ( [ 'full' => __( 'Full', 'elementor-podcast-manager' ), 'trailer' => __( 'Trailer', 'elementor-podcast-manager' ), 'bonus' => __( 'Bonus', 'elementor-podcast-manager' ) ] as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $episode_type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="epm-explicit"><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></label><br />
			<select id="epm-explicit" name="epm[explicit]">
				<?php foreach ( [ 'inherit' => __( 'Inherit podcast setting', 'elementor-podcast-manager' ), 'clean' => __( 'Clean', 'elementor-podcast-manager' ), 'explicit' => __( 'Explicit', 'elementor-podcast-manager' ) ] as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $explicit, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="epm-canonical-url"><?php esc_html_e( 'Canonical URL (optional)', 'elementor-podcast-manager' ); ?></label><br />
			<input type="url" id="epm-canonical-url" name="epm[canonical_url]" value="<?php echo esc_attr( (string) $this->meta( $post, 'canonical_url', '' ) ); ?>" class="widefat" />
		</p>
		<?php
	}

	/**
	 * Guest box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_guest( \WP_Post $post ): void {
		$guest_image_id = (int) $this->meta( $post, 'guest_image_id', 0 );
		$preview        = $guest_image_id > 0 ? wp_get_attachment_image( $guest_image_id, 'thumbnail', false, [ 'class' => 'epm-media-preview__img' ] ) : '';
		?>
		<div class="epm-grid">
			<p>
				<label for="epm-guest-name"><?php esc_html_e( 'Guest name', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-guest-name" name="epm[guest_name]" value="<?php echo esc_attr( (string) $this->meta( $post, 'guest_name', '' ) ); ?>" class="widefat" />
			</p>
			<p>
				<label for="epm-guest-role"><?php esc_html_e( 'Role / job title', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-guest-role" name="epm[guest_role]" value="<?php echo esc_attr( (string) $this->meta( $post, 'guest_role', '' ) ); ?>" class="widefat" />
			</p>
			<p>
				<label for="epm-guest-company"><?php esc_html_e( 'Company', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-guest-company" name="epm[guest_company]" value="<?php echo esc_attr( (string) $this->meta( $post, 'guest_company', '' ) ); ?>" class="widefat" />
			</p>
			<p>
				<label for="epm-guest-bio"><?php esc_html_e( 'Guest bio (optional)', 'elementor-podcast-manager' ); ?></label><br />
				<textarea id="epm-guest-bio" name="epm[guest_bio]" rows="3" class="widefat"><?php echo esc_textarea( (string) $this->meta( $post, 'guest_bio', '' ) ); ?></textarea>
			</p>
		</div>
		<div class="epm-media" data-epm-media="guest">
			<input type="hidden" name="epm[guest_image_id]" value="<?php echo esc_attr( (string) $guest_image_id ); ?>" data-epm-media-id />
			<div class="epm-media-preview" data-epm-media-preview><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<p>
				<button type="button" class="button" data-epm-media-choose data-title="<?php esc_attr_e( 'Choose guest image', 'elementor-podcast-manager' ); ?>">
					<?php esc_html_e( 'Choose image', 'elementor-podcast-manager' ); ?>
				</button>
				<button type="button" class="button-link" data-epm-media-remove>
					<?php esc_html_e( 'Remove', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
		</div>
		<p class="description"><?php esc_html_e( 'All guest fields are optional. Solo episodes work without any guest data.', 'elementor-podcast-manager' ); ?></p>
		<?php
	}

	/**
	 * Short description + show notes box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_notes( \WP_Post $post ): void {
		?>
		<p>
			<label for="epm-short-description"><?php esc_html_e( 'Short description', 'elementor-podcast-manager' ); ?></label><br />
			<textarea id="epm-short-description" name="epm[short_description]" rows="3" class="widefat"><?php echo esc_textarea( (string) $this->meta( $post, 'short_description', '' ) ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Used in lists, cards and the feed summary. Falls back to an excerpt of the full description.', 'elementor-podcast-manager' ); ?></span>
		</p>
		<p>
			<label for="epm-show-notes"><?php esc_html_e( 'Show notes', 'elementor-podcast-manager' ); ?></label><br />
			<?php
			wp_editor(
				(string) $this->meta( $post, 'show_notes', '' ),
				'epm_show_notes',
				[
					'textarea_name' => 'epm[show_notes]',
					'textarea_rows' => 8,
					'media_buttons' => true,
					'teeny'         => false,
				]
			);
			?>
			<span class="description"><?php esc_html_e( 'Rich show notes for this episode. Shown on the episode page; optional and never required for publishing.', 'elementor-podcast-manager' ); ?></span>
		</p>
		<p>
			<label for="epm-video-url"><?php esc_html_e( 'Video URL (optional)', 'elementor-podcast-manager' ); ?></label><br />
			<input type="url" id="epm-video-url" name="epm[video_url]" value="<?php echo esc_attr( (string) $this->meta( $post, 'video_url', '' ) ); ?>" class="widefat" />
		</p>
		<p>
			<label for="epm-youtube-url"><?php esc_html_e( 'YouTube URL (optional)', 'elementor-podcast-manager' ); ?></label><br />
			<input type="url" id="epm-youtube-url" name="epm[youtube_url]" value="<?php echo esc_attr( (string) $this->meta( $post, 'youtube_url', '' ) ); ?>" class="widefat" />
		</p>
		<?php
	}

	/**
	 * Chapters box: repeatable timestamp/title/url rows.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_chapters( \WP_Post $post ): void {
		$chapters = Episodes::normalize_chapters( $this->meta( $post, 'chapters', [] ) );
		?>
		<div class="epm-repeat" data-epm-repeat="chapters">
			<div class="epm-repeat__rows" data-epm-repeat-rows>
				<?php foreach ( $chapters as $index => $chapter ) : ?>
					<?php $this->chapter_row( $index, $chapter ); ?>
				<?php endforeach; ?>
			</div>
			<template data-epm-repeat-template>
				<?php $this->chapter_row( '__INDEX__', [ 'time' => '', 'title' => '', 'url' => '' ] ); ?>
			</template>
			<p>
				<button type="button" class="button" data-epm-repeat-add>
					<?php esc_html_e( 'Add chapter', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
			<p class="description"><?php esc_html_e( 'Optional. Clicking a chapter seeks the player to its timestamp.', 'elementor-podcast-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render one chapter row.
	 *
	 * @param int|string $index Row index.
	 * @param array      $chapter Chapter data.
	 * @return void
	 */
	private function chapter_row( $index, array $chapter ): void {
		?>
		<div class="epm-repeat__row" data-epm-repeat-row>
			<span class="epm-repeat__order">
				<button type="button" class="button-link" data-epm-repeat-up aria-label="<?php esc_attr_e( 'Move chapter up', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move up', 'elementor-podcast-manager' ); ?>">▲</button>
				<button type="button" class="button-link" data-epm-repeat-down aria-label="<?php esc_attr_e( 'Move chapter down', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move down', 'elementor-podcast-manager' ); ?>">▼</button>
			</span>
			<input type="text" name="epm[chapters][<?php echo esc_attr( (string) $index ); ?>][time]" value="<?php echo esc_attr( $chapter['time'] ?? '' ); ?>" placeholder="00:00" class="epm-repeat__time" aria-label="<?php esc_attr_e( 'Timestamp', 'elementor-podcast-manager' ); ?>" />
			<input type="text" name="epm[chapters][<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( $chapter['title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Chapter title', 'elementor-podcast-manager' ); ?>" class="widefat" aria-label="<?php esc_attr_e( 'Chapter title', 'elementor-podcast-manager' ); ?>" />
			<input type="url" name="epm[chapters][<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( $chapter['url'] ?? '' ); ?>" placeholder="https://" class="widefat" aria-label="<?php esc_attr_e( 'Chapter link (optional)', 'elementor-podcast-manager' ); ?>" />
			<button type="button" class="button-link epm-repeat__remove" data-epm-repeat-remove aria-label="<?php esc_attr_e( 'Remove chapter', 'elementor-podcast-manager' ); ?>">×</button>
		</div>
		<?php
	}

	/**
	 * Episode platform links box: optional per-episode overrides.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_platforms( \WP_Post $post ): void {
		$links = Episodes::normalize_links( $this->meta( $post, 'platform_urls', [] ) );
		$services = wp_list_pluck( Directories::services(), 'label' );
		?>
		<div class="epm-repeat" data-epm-repeat="episode-platforms">
			<div class="epm-repeat__rows" data-epm-repeat-rows>
				<?php foreach ( array_values( $links ) as $index => $link ) : ?>
					<?php $this->platform_row( 'epm', (string) $index, $link, $services ); ?>
				<?php endforeach; ?>
			</div>
			<template data-epm-repeat-template>
				<?php $this->platform_row( 'epm', '__INDEX__', [ 'service' => 'custom', 'label' => '', 'url' => '' ], $services ); ?>
			</template>
			<p>
				<button type="button" class="button" data-epm-repeat-add>
					<?php esc_html_e( 'Add platform link', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
			<p class="description"><?php esc_html_e( 'Optional. When set, these replace the podcast-wide platform links for this episode.', 'elementor-podcast-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render one episode platform-link row.
	 *
	 * @param string $group Field group (epm).
	 * @param string $index Row key.
	 * @param array  $link Link data.
	 * @param array  $services Service options.
	 * @return void
	 */
	private function platform_row( string $group, string $index, array $link, array $services ): void {
		$field = $group . '[platform_urls][' . $index . ']';
		?>
		<div class="epm-repeat__row epm-repeat__row--links" data-epm-repeat-row>
			<span class="epm-repeat__order">
				<button type="button" class="button-link" data-epm-repeat-up aria-label="<?php esc_attr_e( 'Move link up', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move up', 'elementor-podcast-manager' ); ?>">▲</button>
				<button type="button" class="button-link" data-epm-repeat-down aria-label="<?php esc_attr_e( 'Move link down', 'elementor-podcast-manager' ); ?>" title="<?php esc_attr_e( 'Move down', 'elementor-podcast-manager' ); ?>">▼</button>
			</span>
			<select name="<?php echo esc_attr( $field ); ?>[service]" aria-label="<?php esc_attr_e( 'Service', 'elementor-podcast-manager' ); ?>">
				<?php foreach ( $services as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $link['service'] ?? 'custom', $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="<?php echo esc_attr( $field ); ?>[label]" value="<?php echo esc_attr( $link['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Label', 'elementor-podcast-manager' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'elementor-podcast-manager' ); ?>" />
			<input type="url" name="<?php echo esc_attr( $field ); ?>[url]" value="<?php echo esc_attr( $link['url'] ?? '' ); ?>" placeholder="https://" class="widefat" aria-label="<?php esc_attr_e( 'URL', 'elementor-podcast-manager' ); ?>" />
			<button type="button" class="button-link epm-repeat__remove" data-epm-repeat-remove aria-label="<?php esc_attr_e( 'Remove link', 'elementor-podcast-manager' ); ?>">×</button>
		</div>
		<?php
	}

	/**
	 * Transcript box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_transcript( \WP_Post $post ): void {
		wp_editor(
			(string) $this->meta( $post, 'transcript', '' ),
			'epm_transcript',
			[
				'textarea_name' => 'epm[transcript]',
				'media_buttons' => false,
				'teeny'         => true,
				'textarea_rows' => 10,
			]
		);
		echo '<p class="description">' . esc_html__( 'Optional. Rendered as semantic HTML by the Transcript widget.', 'elementor-podcast-manager' ) . '</p>';
	}

	/**
	 * Save handler.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		// Verify nonce.
		if ( ! isset( $_POST['epm_episode_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['epm_episode_meta_nonce'] ), 'epm_episode_meta' ) ) {
			return;
		}

		// Autosaves and revisions are handled by core.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['epm'] ) || ! is_array( $_POST['epm'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$input = wp_unslash( $_POST['epm'] );
		$p     = Episodes::META_PREFIX;

		// --- Audio association: validate, persist, keep derived metadata in sync. ---
		$old_audio_id = (int) get_post_meta( $post_id, $p . 'audio_id', true );
		$new_audio_id = absint( $input['audio_id'] ?? 0 );

		if ( $new_audio_id > 0 && ! AudioMetadata::is_valid_audio_attachment( $new_audio_id ) ) {
			self::add_notice(
				__( 'The selected audio file is not a supported audio attachment. The previous audio association was kept.', 'elementor-podcast-manager' ),
				'error'
			);
			$new_audio_id = $old_audio_id;
		}

		update_post_meta( $post_id, $p . 'audio_id', $new_audio_id );

		// Manual duration: validate format, keep old value on invalid input.
		$manual_duration = sanitize_text_field( $input['duration'] ?? '' );
		if ( '' !== $manual_duration && ! AudioMetadata::is_valid_duration( $manual_duration ) ) {
			self::add_notice(
				__( 'The manual duration was not saved: use minutes:seconds (e.g. 48:12) or hours:minutes:seconds.', 'elementor-podcast-manager' ),
				'error'
			);
			$manual_duration = (string) get_post_meta( $post_id, $p . 'duration', true );
		}
		update_post_meta( $post_id, $p . 'duration', $manual_duration );

		if ( $new_audio_id !== $old_audio_id ) {
			// Association changed or was removed: drop derived metadata, then re-detect.
			delete_post_meta( $post_id, $p . 'audio_size' );

			if ( $new_audio_id > 0 ) {
				$detected = AudioMetadata::detect( $new_audio_id );
				if ( '' !== $detected['duration'] ) {
					update_post_meta( $post_id, $p . 'duration', $detected['duration'] );
				}
				if ( $detected['size'] > 0 ) {
					update_post_meta( $post_id, $p . 'audio_size', $detected['size'] );
				}
				if ( '' === $detected['duration'] && '' === $manual_duration ) {
					self::add_notice(
						__( 'Duration could not be detected automatically. You can enter it manually below the audio upload.', 'elementor-podcast-manager' ),
						'warning'
					);
				}
			} else {
				delete_post_meta( $post_id, $p . 'duration' );
			}
		} elseif ( $new_audio_id > 0 ) {
			// Same attachment: backfill missing derived metadata only. Never blocks publishing.
			AudioMetadata::refresh( $post_id );
		}

		update_post_meta( $post_id, $p . 'artwork_id', absint( $input['artwork_id'] ?? 0 ) );
		update_post_meta( $post_id, $p . 'guest_image_id', absint( $input['guest_image_id'] ?? 0 ) );

		update_post_meta( $post_id, $p . 'short_description', sanitize_textarea_field( $input['short_description'] ?? '' ) );
		update_post_meta( $post_id, $p . 'show_notes', wp_kses_post( $input['show_notes'] ?? '' ) );

		// Episode-specific platform links (override/extend the podcast defaults).
		$platform_urls = [];
		if ( isset( $input['platform_urls'] ) && is_array( $input['platform_urls'] ) ) {
			foreach ( $input['platform_urls'] as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}
				$url = esc_url_raw( $link['url'] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				$platform_urls[] = [
					'service' => sanitize_key( $link['service'] ?? 'custom' ),
					'label'   => sanitize_text_field( $link['label'] ?? '' ),
					'url'     => $url,
				];
			}
		}
		update_post_meta( $post_id, $p . 'platform_urls', $platform_urls );

		$episode_number = $input['episode_number'] ?? '';
		update_post_meta( $post_id, $p . 'episode_number', '' === $episode_number ? '' : absint( $episode_number ) );

		$season_number = $input['season_number'] ?? '';
		update_post_meta( $post_id, $p . 'season_number', '' === $season_number ? '' : absint( $season_number ) );

		$episode_type = $input['episode_type'] ?? 'full';
		update_post_meta( $post_id, $p . 'episode_type', in_array( $episode_type, [ 'full', 'trailer', 'bonus' ], true ) ? $episode_type : 'full' );

		$explicit = $input['explicit'] ?? 'inherit';
		update_post_meta( $post_id, $p . 'explicit', in_array( $explicit, [ 'inherit', 'clean', 'explicit' ], true ) ? $explicit : 'inherit' );

		update_post_meta( $post_id, $p . 'guest_name', sanitize_text_field( $input['guest_name'] ?? '' ) );
		update_post_meta( $post_id, $p . 'guest_role', sanitize_text_field( $input['guest_role'] ?? '' ) );
		update_post_meta( $post_id, $p . 'guest_company', sanitize_text_field( $input['guest_company'] ?? '' ) );
		update_post_meta( $post_id, $p . 'guest_bio', sanitize_textarea_field( $input['guest_bio'] ?? '' ) );

		update_post_meta( $post_id, $p . 'video_url', esc_url_raw( $input['video_url'] ?? '' ) );
		update_post_meta( $post_id, $p . 'youtube_url', esc_url_raw( $input['youtube_url'] ?? '' ) );
		update_post_meta( $post_id, $p . 'canonical_url', esc_url_raw( $input['canonical_url'] ?? '' ) );

		update_post_meta( $post_id, $p . 'transcript', wp_kses_post( $input['transcript'] ?? '' ) );

		// Chapters.
		$chapters = [];
		if ( isset( $input['chapters'] ) && is_array( $input['chapters'] ) ) {
			foreach ( $input['chapters'] as $chapter ) {
				if ( ! is_array( $chapter ) ) {
					continue;
				}
				$time  = sanitize_text_field( $chapter['time'] ?? '' );
				$title = sanitize_text_field( $chapter['title'] ?? '' );
				if ( '' === $time || '' === $title ) {
					continue;
				}
				$chapters[] = [
					'time'  => $time,
					'title' => $title,
					'url'   => esc_url_raw( $chapter['url'] ?? '' ),
				];
			}
		}
		update_post_meta( $post_id, $p . 'chapters', $chapters );

		// Numeric duration for sorting (list table, integrations).
		Episodes::sync_duration_seconds( $post_id );

		// Ensure an immutable GUID exists (idempotent; migration covers older episodes).
		Episodes::get_guid( $post_id );

		// Invalidate the per-request data cache after writes.
		Episodes::clear_data_cache( $post_id );

		// Persist any queued notices across the save redirect.
		self::persist_notices( $post_id );
	}

	/**
	 * AJAX handler for drag & drop audio uploads.
	 *
	 * @return void
	 */
	public function ajax_upload_audio(): void {
		check_ajax_referer( 'epm_episode_meta' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to upload files.', 'elementor-podcast-manager' ) ] );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to edit this episode.', 'elementor-podcast-manager' ) ] );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
		}

		// Only audio files are accepted.
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( strpos( $mime, 'audio/' ) !== 0 ) {
			wp_delete_attachment( $attachment_id, true );
			wp_send_json_error( [ 'message' => __( 'Please upload an audio file (MP3, M4A or WAV).', 'elementor-podcast-manager' ) ] );
		}

		wp_send_json_success( [ 'id' => $attachment_id ] );
	}

	/**
	 * AJAX: describe an audio attachment for in-place editor updates.
	 *
	 * @return void
	 */
	public function ajax_audio_describe(): void {
		check_ajax_referer( 'epm_episode_meta' );

		$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( [ 'message' => __( 'You are not allowed to edit this episode.', 'elementor-podcast-manager' ) ] );
			}
		} elseif ( ! Capabilities::can_manage_episodes() ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to manage episodes.', 'elementor-podcast-manager' ) ] );
		}

		if ( ! AudioMetadata::is_valid_audio_attachment( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => __( 'The selected file is not a supported audio file.', 'elementor-podcast-manager' ) ] );
		}

		wp_send_json_success( AudioMetadata::describe( $attachment_id ) );
	}

	/**
	 * AJAX: searchable, paginated episode list for the Elementor editor.
	 *
	 * Reaches the whole catalog (no 100-item cap). Only users who can
	 * manage episodes may search; only titles/dates of episodes they
	 * could edit are exposed.
	 *
	 * @return void
	 */
	public function ajax_episode_search(): void {
		check_ajax_referer( 'epm_episode_search' );

		if ( ! Capabilities::can_manage_episodes() ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to manage episodes.', 'elementor-podcast-manager' ) ] );
		}

		$include = isset( $_POST['include'] ) ? absint( $_POST['include'] ) : 0;
		$term    = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$page    = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		$query_args = [
			'post_type'      => EpisodePostType::CPT,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => 20,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		];

		if ( $include > 0 ) {
			$query_args['post__in'] = [ $include ];
			$query_args['posts_per_page'] = 1;
		} elseif ( '' !== $term ) {
			$query_args['s'] = $term;
		}

		$query = new \WP_Query( $query_args );

		$status_labels = [
			'draft'   => __( 'Draft', 'elementor-podcast-manager' ),
			'pending' => __( 'Pending review', 'elementor-podcast-manager' ),
			'future'  => __( 'Scheduled', 'elementor-podcast-manager' ),
			'private' => __( 'Private', 'elementor-podcast-manager' ),
		];

		$items = [];
		foreach ( $query->posts as $post ) {
			// Raw title (get_the_title() would add "Protected:"/"Private:"
			// prefixes; the status is shown separately). Plain text: the
			// editor inserts it with jQuery .text().
			$title = html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$status = $status_labels[ $post->post_status ] ?? '';
			if ( '' === $status && '' !== $post->post_password ) {
				$status = __( 'Password protected', 'elementor-podcast-manager' );
			}

			$items[] = [
				'id'     => $post->ID,
				'title'  => '' !== trim( $title )
					? $title
					/* translators: %d: episode post ID */
					: sprintf( __( 'Episode #%d', 'elementor-podcast-manager' ), $post->ID ),
				'date'   => get_the_date( '', $post ),
				'status' => $status,
			];
		}

		wp_send_json_success(
			[
				'items' => $items,
				'more'  => $query->max_num_pages > $page,
			]
		);
	}
}
