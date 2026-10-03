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
	 * @var array<int, array{message: string, type: string, anchor?: string, link?: string}>
	 */
	private static array $notices = [];

	/**
	 * Field errors of the current save, shown next to the fields after the
	 * save redirect. Keys: audio_url, duration (message + rejected value),
	 * chapters and platform_urls (rows that were not saved).
	 *
	 * @var array<string, mixed>
	 */
	private static array $field_errors = [];

	/**
	 * Field errors loaded for the screen being rendered (per post ID).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $loaded_errors = [];

	/**
	 * Chapter start time: seconds, m:ss, mm:ss or h:mm:ss. Shared by the
	 * HTML pattern and the save handler.
	 */
	private const TIME_PATTERN = '\d+(:[0-5]?\d){0,2}';

	/**
	 * Wire meta boxes and saving.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes_' . EpisodePostType::CPT, [ $this, 'register_boxes' ] );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'use_block_editor' ], 10, 2 );
		add_filter( 'default_hidden_meta_boxes', [ $this, 'default_hidden_boxes' ], 10, 2 );
		add_action( 'edit_form_after_title', [ $this, 'render_after_title' ] );
		add_action( 'save_post_' . EpisodePostType::CPT, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_epm_upload_audio', [ $this, 'ajax_upload_audio' ] );
		add_action( 'wp_ajax_epm_audio_describe', [ $this, 'ajax_audio_describe' ] );
		add_action( 'wp_ajax_epm_audio_url_check', [ $this, 'ajax_audio_url_check' ] );
		add_action( 'wp_ajax_epm_episode_search', [ $this, 'ajax_episode_search' ] );
		add_action( 'admin_notices', [ self::class, 'render_notices' ] );
	}

	/**
	 * Queue an admin notice for the episode editor.
	 *
	 * @param string $message Notice text.
	 * @param string $type    Notice type: error, warning, success, info.
	 * @param string $anchor  Optional ID of the field the notice is about.
	 * @param string $link    Label of the link to that field.
	 * @return void
	 */
	public static function add_notice( string $message, string $type = 'info', string $anchor = '', string $link = '' ): void {
		self::$notices[] = [
			'message' => $message,
			'type'    => $type,
			'anchor'  => $anchor,
			'link'    => $link,
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

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.
		if ( $post_id > 0 ) {
			$key   = self::notice_transient_key( $post_id );
			$saved = get_transient( $key );
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				$notices = array_merge( $notices, $saved );
				delete_transient( $key );
			}
		}

		foreach ( $notices as $notice ) {
			$link = '';
			if ( ! empty( $notice['anchor'] ) && ! empty( $notice['link'] ) ) {
				$link = ' <a href="#' . esc_attr( (string) $notice['anchor'] ) . '" data-epm-focus-field>' . esc_html( (string) $notice['link'] ) . '</a>';
			}

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
				esc_attr( (string) $notice['type'] ),
				esc_html( (string) $notice['message'] ),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
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
	 * Transient key for cross-redirect field errors.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function field_error_key( int $post_id ): string {
		return 'epm_field_errors_' . get_current_user_id() . '_' . $post_id;
	}

	/**
	 * Persist queued notices and field errors across the post-save redirect.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function persist_notices( int $post_id ): void {
		if ( ! empty( self::$notices ) ) {
			set_transient( self::notice_transient_key( $post_id ), self::$notices, MINUTE_IN_SECONDS );
		}

		if ( ! empty( self::$field_errors ) ) {
			set_transient( self::field_error_key( $post_id ), self::$field_errors, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Field errors of the last save, read once per request.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function field_errors( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		if ( ! isset( self::$loaded_errors[ $post_id ] ) ) {
			$key    = self::field_error_key( $post_id );
			$errors = get_transient( $key );
			if ( false !== $errors ) {
				delete_transient( $key );
			}
			self::$loaded_errors[ $post_id ] = is_array( $errors ) ? $errors : [];
		}

		return self::$loaded_errors[ $post_id ];
	}

	/**
	 * Enqueue admin assets for the episode editor and list.
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
		Admin::enqueue_assets();
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
	 * Hide the core Excerpt, Custom Fields and Author boxes by default on
	 * episodes: the podcast fields come first, and the short description
	 * already covers the excerpt. They stay one click away in Screen Options
	 * (and a saved Screen Options choice always wins).
	 *
	 * @param string[]   $hidden Hidden box IDs.
	 * @param \WP_Screen $screen Screen.
	 * @return string[]
	 */
	public function default_hidden_boxes( $hidden, $screen ): array {
		$hidden = (array) $hidden;

		if ( $screen instanceof \WP_Screen && EpisodePostType::CPT === $screen->post_type ) {
			$hidden = array_values( array_unique( array_merge( $hidden, [ 'postexcerpt', 'postcustom', 'authordiv' ] ) ) );
		}

		return $hidden;
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
 echo '<nav class="epm-episode-basics" aria-label="' . esc_attr__( 'Publish an episode', 'elementor-podcast-manager' ) . '"><strong>' . esc_html__( 'Start with audio, then description, then publish.', 'elementor-podcast-manager' ) . '</strong><p><a href="#epm-audio">' . esc_html__( 'Audio', 'elementor-podcast-manager' ) . '</a> · <a href="#postdivrich">' . esc_html__( 'Description', 'elementor-podcast-manager' ) . '</a> · <a href="#submitdiv">' . esc_html__( 'Publish', 'elementor-podcast-manager' ) . '</a></p><details><summary>' . esc_html__( 'Optional episode details', 'elementor-podcast-manager' ) . '</summary><p>' . esc_html__( 'Artwork, episode information, chapters, guest, platform links, transcript and video are available below and in the sidebar. Use Screen Options to show or hide boxes; your arrangement is kept.', 'elementor-podcast-manager' ) . '</p></details></nav>';
		do_meta_boxes( get_current_screen(), 'epm_after_title', $post );
		echo '<h2 class="epm-description-heading">' . esc_html__( 'Episode description', 'elementor-podcast-manager' ) . '</h2>';
		echo '</div>';
	}

	/**
	 * Register grouped meta boxes, most-used first.
	 *
	 * @param \WP_Post|null $post Post being edited.
	 * @return void
	 */
	public function register_boxes( $post = null ): void {
		add_meta_box(
			'epm-audio',
			__( 'Episode audio', 'elementor-podcast-manager' ),
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
			__( 'Episode information', 'elementor-podcast-manager' ),
			[ $this, 'box_details' ],
			EpisodePostType::CPT,
			'side',
			'default'
		);

		add_meta_box(
			'epm-notes',
			__( 'Summary, show notes and video', 'elementor-podcast-manager' ),
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
			'epm-guest',
			__( 'Guest', 'elementor-podcast-manager' ),
			[ $this, 'box_guest' ],
			EpisodePostType::CPT,
			'normal',
			'default'
		);

		add_meta_box(
			'epm-platforms',
			__( 'Platform links for this episode', 'elementor-podcast-manager' ),
			[ $this, 'box_platforms' ],
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
	 * Short, human format name of a media type (MP3, M4A, WAV …).
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	public static function format_label( string $mime ): string {
		$mime   = strtolower( trim( $mime ) );
		$labels = [
			'audio/mpeg'      => 'MP3',
			'audio/mp3'       => 'MP3',
			'audio/mp4'       => 'M4A',
			'audio/x-m4a'     => 'M4A',
			'audio/m4a'       => 'M4A',
			'audio/aac'       => 'AAC',
			'audio/wav'       => 'WAV',
			'audio/x-wav'     => 'WAV',
			'audio/wave'      => 'WAV',
			'audio/ogg'       => 'Ogg',
			'audio/opus'      => 'Opus',
			'audio/flac'      => 'FLAC',
			'video/mp4'       => __( 'MP4 video', 'elementor-podcast-manager' ),
			'video/x-m4v'     => __( 'M4V video', 'elementor-podcast-manager' ),
			'video/quicktime' => __( 'QuickTime video', 'elementor-podcast-manager' ),
		];

		if ( isset( $labels[ $mime ] ) ) {
			return $labels[ $mime ];
		}

		$slash = strrpos( $mime, '/' );

		return false !== $slash ? strtoupper( substr( $mime, $slash + 1 ) ) : '';
	}

	/**
	 * Host name of a URL without "www.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_label( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Validate an audio address typed by a person: http(s) with a host.
	 *
	 * @param string $raw Raw input.
	 * @return string Clean URL, '' when it is not usable.
	 */
	private static function clean_audio_url( string $raw ): string {
		$raw = trim( $raw );

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return '';
		}

		$url = esc_url_raw( $raw, [ 'http', 'https' ] );

		return ( '' !== $url && '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ) ? $url : '';
	}

	/**
	 * Ask the server holding an audio file for its size and type.
	 *
	 * HEAD first (redirects followed, as with tracking prefixes); when the
	 * server refuses HEAD or sends no length, a one-byte range request
	 * reads the total from Content-Range. wp_safe_remote_* refuses private
	 * network targets.
	 *
	 * @param string $url Audio URL.
	 * @return array{ok: bool, status: int, mime: string, size: int, page: bool, error: string}
	 */
	private static function probe_audio_url( string $url ): array {
		$out = [
			'ok'     => false,
			'status' => 0,
			'mime'   => '',
			'size'   => 0,
			'page'   => false,
			'error'  => '',
		];

		$args = [
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => 'ElementorPodcastManager/' . EPM_VERSION . '; ' . home_url( '/' ),
		];

		$header = static function ( $response, string $name ): string {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_array( $value ) ) {
				$value = end( $value );
			}

			return trim( (string) $value );
		};

		$read = static function ( $response ) use ( &$out, $header, $url ): void {
			$out['status'] = (int) wp_remote_retrieve_response_code( $response );
			$out['ok']     = $out['status'] >= 200 && $out['status'] < 300;

			if ( ! $out['ok'] ) {
				return;
			}

			$type = strtolower( trim( explode( ';', $header( $response, 'content-type' ) )[0] ) );
			if ( '' !== $type ) {
				$out['page'] = 0 === strpos( $type, 'text/html' );
				$out['mime'] = ( 0 === strpos( $type, 'audio/' ) || 0 === strpos( $type, 'video/' ) )
					? AudioMetadata::normalize_mime( $type, $url )
					: '';
			}

			$range = $header( $response, 'content-range' );
			if ( '' !== $range && preg_match( '#/(\d+)\s*$#', $range, $match ) ) {
				$out['size'] = (int) $match[1];
			} elseif ( 206 !== $out['status'] ) {
				$out['size'] = (int) $header( $response, 'content-length' );
			}
		};

		$response = SafeHttp::head( $url, $args );

		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
		} else {
			$read( $response );
		}

		// Servers that refuse HEAD (405, 403 …) or send no length.
		if ( ! is_wp_error( $response ) && ( ! $out['ok'] || $out['size'] <= 0 ) ) {
			$ranged = SafeHttp::get(
				$url,
				$args + [
					'headers'             => [ 'Range' => 'bytes=0-0' ],
					'limit_response_size' => 4096,
				]
			);
			if ( ! is_wp_error( $ranged ) ) {
				$status = (int) wp_remote_retrieve_response_code( $ranged );
				if ( $status >= 200 && $status < 300 ) {
					$read( $ranged );
				}
			}
		}

		if ( '' === $out['mime'] ) {
			$out['mime'] = AudioMetadata::normalize_mime( '', $url );
		}

		return $out;
	}

	/**
	 * Problem found while checking an audio URL, in words that say how to
	 * fix it ('' when there is none).
	 *
	 * @param array<string, mixed> $probe Result of probe_audio_url().
	 * @return string
	 */
	private static function probe_message( array $probe ): string {
		if ( '' !== (string) $probe['error'] ) {
			return sprintf(
				/* translators: %s: technical error message */
				__( 'The file could not be reached (%s). Check that the address opens the audio file in a browser.', 'elementor-podcast-manager' ),
				(string) $probe['error']
			);
		}

		if ( ! $probe['ok'] ) {
			return sprintf(
				/* translators: %d: HTTP status code, e.g. 404 */
				__( 'The file could not be reached (HTTP %d). Check that the address opens the audio file in a browser.', 'elementor-podcast-manager' ),
				(int) $probe['status']
			);
		}

		if ( $probe['page'] ) {
			return __( 'This address opens a web page, not an audio file. Use the direct link to the MP3 or M4A file.', 'elementor-podcast-manager' );
		}

		if ( ! AudioMetadata::is_distribution_format( (string) $probe['mime'] ) ) {
			return sprintf(
				/* translators: %s: format name, e.g. WAV */
				__( 'Podcast apps can’t play this format (%s). Link an MP3 or M4A file to include the episode in the feed.', 'elementor-podcast-manager' ),
				self::format_label( (string) $probe['mime'] )
			);
		}

		return '';
	}

	/**
	 * Audio box: prominent upload area, or an audio URL for files hosted
	 * elsewhere.
	 *
	 * Selection and upload update the box in place via JS — no page reload,
	 * so unsaved title/description/fields are preserved. The attachment ID
	 * persists through the normal secured save_post workflow. A Media
	 * Library file always wins over an audio URL.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_audio( \WP_Post $post ): void {
		$this->nonce();

		$audio_id  = (int) $this->meta( $post, 'audio_id', 0 );
		$has_media = $audio_id > 0 && wp_get_attachment_url( $audio_id );
		$duration  = (string) $this->meta( $post, 'duration', '' );
		$audio_url = (string) $this->meta( $post, 'audio_url', '' );
		$errors    = self::field_errors( $post->ID );
		$url_error = is_array( $errors['audio_url'] ?? null ) ? $errors['audio_url'] : [];
		$dur_error = is_array( $errors['duration'] ?? null ) ? $errors['duration'] : [];
		$url_value = '' !== ( $url_error['value'] ?? '' ) ? (string) $url_error['value'] : $audio_url;
		$external  = ! $has_media && '' !== $audio_url;
		$has_audio = $has_media || $external;

		$detected = '';
		if ( $has_media ) {
			$detected = (string) AudioMetadata::describe( $audio_id )['duration'];
		}

		if ( $has_media && '' !== $detected ) {
			/* translators: %s: detected duration */
			$duration_hint = sprintf( __( 'Detected from the file: %s. Enter a duration only if this is wrong (mm:ss or hh:mm:ss).', 'elementor-podcast-manager' ), $detected );
		} elseif ( $external ) {
			$duration_hint = __( 'Enter the duration as mm:ss or hh:mm:ss. It can’t be read from an audio URL.', 'elementor-podcast-manager' );
		} else {
			$duration_hint = __( 'Detected automatically when you add audio. Enter it only if detection fails (mm:ss or hh:mm:ss).', 'elementor-podcast-manager' );
		}

		$url_described = 'epm-audio-url-help' . ( ! empty( $url_error ) ? ' epm-audio-url-error' : '' );
		?>
		<div class="epm-upload" data-epm-upload="audio" data-epm-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<?php $this->import_note( $post ); ?>
			<input type="hidden" name="epm[audio_id]" value="<?php echo esc_attr( (string) $audio_id ); ?>" data-epm-audio-id />
			<div class="epm-upload__drop" data-epm-drop>
				<div class="epm-upload__state" data-epm-audio-state>
					<?php $this->audio_state_html( $post, $has_media ? $audio_id : 0, $external ? $audio_url : '' ); ?>
				</div>
				<p class="epm-upload__actions">
					<button type="button" class="button button-primary button-large" data-epm-choose-audio>
						<?php echo $has_media ? esc_html__( 'Replace audio', 'elementor-podcast-manager' ) : esc_html__( 'Upload episode audio', 'elementor-podcast-manager' ); ?>
					</button>
					<button type="button" class="button button-large" data-epm-remove-audio<?php echo $has_audio ? '' : ' hidden'; ?>>
						<?php esc_html_e( 'Remove audio', 'elementor-podcast-manager' ); ?>
					</button>
				</p>
			</div>
			<progress class="epm-upload__progress" data-epm-progress max="100" value="0" aria-labelledby="epm-upload-status" hidden></progress>
			<p class="epm-upload__status" id="epm-upload-status" role="status" data-epm-status></p>
			<div class="epm-upload__error notice notice-error inline" data-epm-error role="alert" hidden></div>
			<p class="description">
				<?php esc_html_e( 'MP3 or M4A works in every podcast app. WAV is kept for storage only and never reaches the feed.', 'elementor-podcast-manager' ); ?>
			</p>

			<details class="epm-audio-url" data-epm-audio-url<?php echo ( '' !== $url_value ) ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Use an audio URL instead', 'elementor-podcast-manager' ); ?></summary>
				<div class="epm-audio-url__body">
					<label class="epm-audio-url__label" for="epm-audio-url"><?php esc_html_e( 'Audio URL', 'elementor-podcast-manager' ); ?></label>
					<div class="epm-audio-url__row">
						<input type="url" id="epm-audio-url" name="epm[audio_url]" value="<?php echo esc_attr( $url_value ); ?>" class="large-text code" inputmode="url" spellcheck="false" autocomplete="off" pattern="https?://.+" data-epm-audio-url-input data-epm-error="<?php esc_attr_e( 'Enter a web address that starts with https://, for example https://cdn.example.com/episode-12.mp3.', 'elementor-podcast-manager' ); ?>" aria-describedby="<?php echo esc_attr( $url_described ); ?>"<?php echo ! empty( $url_error ) ? ' aria-invalid="true"' : ''; ?> />
						<button type="button" class="button" data-epm-audio-url-check><?php esc_html_e( 'Check URL', 'elementor-podcast-manager' ); ?></button>
					</div>
					<p class="epm-field-error" id="epm-audio-url-error" data-epm-audio-url-error<?php echo empty( $url_error ) ? ' hidden' : ''; ?>><?php echo esc_html( (string) ( $url_error['message'] ?? '' ) ); ?></p>
					<p class="description" id="epm-audio-url-help"><?php esc_html_e( 'For audio on a CDN, a storage bucket or a podcast host: paste the direct link to the MP3 or M4A file. The file must stay online at this address.', 'elementor-podcast-manager' ); ?></p>
					<p class="description epm-audio-url__inactive" data-epm-audio-url-inactive<?php echo ( $has_media && '' !== $audio_url ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Not used while a Media Library file is attached. Remove that file to use this address.', 'elementor-podcast-manager' ); ?></p>
				</div>
			</details>

			<div class="epm-upload__duration">
				<label for="epm-duration"><?php esc_html_e( 'Duration', 'elementor-podcast-manager' ); ?></label>
				<input type="text" id="epm-duration" name="epm[duration]" value="<?php echo esc_attr( '' !== (string) ( $dur_error['value'] ?? '' ) ? (string) $dur_error['value'] : $duration ); ?>" class="regular-text" autocomplete="off" aria-describedby="<?php echo esc_attr( 'epm-duration-hint' . ( ! empty( $dur_error ) ? ' epm-duration-error' : '' ) ); ?>"<?php echo ! empty( $dur_error ) ? ' aria-invalid="true"' : ''; ?> />
				<?php if ( ! empty( $dur_error ) ) : ?>
					<p class="epm-field-error" id="epm-duration-error"><?php echo esc_html( (string) $dur_error['message'] ); ?></p>
				<?php endif; ?>
				<p class="description" id="epm-duration-hint" data-epm-duration-hint><?php echo esc_html( $duration_hint ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Note on episodes imported from another host's feed.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	private function import_note( \WP_Post $post ): void {
		if ( 'import' !== (string) $this->meta( $post, 'source', '' ) ) {
			return;
		}

		$link = esc_url_raw( (string) $this->meta( $post, 'source_link', '' ), [ 'http', 'https' ] );
		$feed = (string) $this->meta( $post, 'source_feed', '' );
		$name = '';

		if ( '' !== $feed ) {
			$provider = Providers::detect( $feed );
			$details  = null !== $provider ? Providers::get( $provider ) : null;
			$name     = null !== $details ? (string) $details['name'] : self::host_label( $feed );
		}
		if ( '' === $name && '' !== $link ) {
			$name = self::host_label( $link );
		}
		if ( '' === $name ) {
			$name = __( 'another podcast feed', 'elementor-podcast-manager' );
		}

		$source = '' !== $link
			? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'elementor-podcast-manager' ) . '</span></a>'
			: esc_html( $name );
		?>
		<div class="notice notice-info inline epm-upload__import epm-import-note">
			<p>
				<?php
				printf(
					/* translators: %s: name of the podcast host, linked to the original episode */
					esc_html__( 'Imported from %s. Edits you make here are kept when episodes sync from your host.', 'elementor-podcast-manager' ),
					$source // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the current audio state (file or URL details + preview, or the
	 * empty hint). Mirrors renderAudioState() in admin/js/epm-admin-ui.js.
	 *
	 * @param \WP_Post $post      Post object.
	 * @param int      $audio_id  Attachment ID (0 when none).
	 * @param string   $audio_url Audio URL in use (when no attachment).
	 * @return void
	 */
	private function audio_state_html( \WP_Post $post, int $audio_id, string $audio_url = '' ): void {
		if ( $audio_id > 0 ) {
			$info     = AudioMetadata::describe( $audio_id );
			$duration = '' !== (string) $info['duration'] ? (string) $info['duration'] : (string) $this->meta( $post, 'duration', '' );
			$size     = '' !== (string) $info['size_formatted'] ? (string) $info['size_formatted'] : ( (int) $this->meta( $post, 'audio_size', 0 ) > 0 ? size_format( (int) $this->meta( $post, 'audio_size', 0 ) ) : '' );
			$meta     = array_filter( [ self::format_label( (string) $info['mime'] ), $duration, (string) $size ] );
			?>
			<p class="epm-upload__file">
				<span class="epm-upload__name"><?php echo esc_html( (string) $info['filename'] ); ?></span>
				<?php if ( ! empty( $meta ) ) : ?>
					<span class="epm-upload__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( ! $info['is_distribution'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Podcast apps won’t get this file. Replace it with an MP3 or M4A to include this episode in your feed.', 'elementor-podcast-manager' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== (string) $info['url'] ) : ?>
				<audio controls preload="none" src="<?php echo esc_url( (string) $info['url'] ); ?>" class="epm-upload__preview"></audio>
			<?php endif; ?>
			<?php
			return;
		}

		if ( '' !== $audio_url ) {
			$mime = AudioMetadata::normalize_mime( (string) $this->meta( $post, 'audio_type', '' ), $audio_url );
			$size = (int) $this->meta( $post, 'audio_length', 0 );
			$host = self::host_label( $audio_url );
			$meta = array_filter( [ self::format_label( $mime ), (string) $this->meta( $post, 'duration', '' ), $size > 0 ? (string) size_format( $size ) : '' ] );
			?>
			<p class="epm-upload__file">
				<span class="epm-upload__source"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php esc_html_e( 'Audio URL', 'elementor-podcast-manager' ); ?></span>
				<span class="epm-upload__name"><?php echo esc_html( $host ); ?></span>
				<?php if ( ! empty( $meta ) ) : ?>
					<span class="epm-upload__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
				<?php endif; ?>
				<span class="epm-upload__url"><?php echo esc_html( $audio_url ); ?></span>
			</p>
			<?php if ( ! AudioMetadata::is_distribution_format( $mime ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Podcast apps won’t get this file. Replace it with an MP3 or M4A to include this episode in your feed.', 'elementor-podcast-manager' ); ?></p></div>
			<?php endif; ?>
			<audio controls preload="none" src="<?php echo esc_url( $audio_url ); ?>" class="epm-upload__preview"></audio>
			<p class="epm-upload__note">
				<?php
				/* translators: %s: host name */
				echo esc_html( sprintf( __( 'This file stays at %s. Keep it online at this address: the feed and the player load it from there.', 'elementor-podcast-manager' ), $host ) );
				?>
			</p>
			<?php
			return;
		}

		echo '<p class="epm-upload__hint">' . esc_html__( 'Drop an MP3 or M4A file here. You can also choose a file below.', 'elementor-podcast-manager' ) . '</p>';
	}

	/**
	 * Artwork box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_artwork( \WP_Post $post ): void {
		Admin::media_picker(
			[
				'name'        => 'epm[artwork_id]',
				'key'         => 'artwork',
				'id'          => (int) $this->meta( $post, 'artwork_id', 0 ),
				'frame_title' => __( 'Choose episode artwork', 'elementor-podcast-manager' ),
				'remove'      => __( 'Remove artwork', 'elementor-podcast-manager' ),
				'size'        => 'medium',
				'describedby' => 'epm-artwork-help',
			]
		);
		?>
		<p class="description" id="epm-artwork-help"><?php esc_html_e( 'Optional square image (1400–3000 px, JPEG or PNG). Without one, the featured image or the podcast artwork is used.', 'elementor-podcast-manager' ); ?></p>
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
		$next           = self::next_episode_numbers( $post->ID );
		?>
		<div class="epm-details-field">
			<label for="epm-episode-number"><?php esc_html_e( 'Episode number', 'elementor-podcast-manager' ); ?></label>
			<div class="epm-number-row">
				<input type="number" id="epm-episode-number" name="epm[episode_number]" value="<?php echo esc_attr( (string) $episode_number ); ?>" class="small-text" min="0" step="1" inputmode="numeric" aria-describedby="epm-episode-number-next" />
				<button type="button" class="button" data-epm-next-number hidden><?php esc_html_e( 'Use next number', 'elementor-podcast-manager' ); ?></button>
			</div>
			<p class="description epm-number-hint" id="epm-episode-number-next" data-epm-next-hint data-epm-next-numbers="<?php echo esc_attr( (string) wp_json_encode( $next ) ); ?>" hidden></p>
		</div>
		<div class="epm-details-field">
			<label for="epm-season-number"><?php esc_html_e( 'Season number', 'elementor-podcast-manager' ); ?></label>
			<input type="number" id="epm-season-number" name="epm[season_number]" value="<?php echo esc_attr( (string) $season_number ); ?>" class="small-text" min="0" step="1" inputmode="numeric" />
		</div>
		<div class="epm-details-field">
			<label for="epm-episode-type"><?php esc_html_e( 'Episode type', 'elementor-podcast-manager' ); ?></label>
			<select id="epm-episode-type" name="epm[episode_type]">
				<?php foreach ( Admin::episode_types() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $episode_type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="epm-details-field">
			<label for="epm-explicit"><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></label>
			<select id="epm-explicit" name="epm[explicit]">
				<?php foreach ( Admin::explicit_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $explicit, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="epm-details-field">
			<label for="epm-canonical-url"><?php esc_html_e( 'Canonical URL', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></label>
			<input type="url" id="epm-canonical-url" name="epm[canonical_url]" value="<?php echo esc_attr( (string) $this->meta( $post, 'canonical_url', '' ) ); ?>" class="widefat" inputmode="url" spellcheck="false" />
		</div>
		<?php
	}

	/**
	 * Next free episode numbers: the highest number in use plus one, over
	 * all episodes and per season. Counts published, scheduled, draft,
	 * pending and private episodes; trashed episodes and the episode being
	 * edited are left out.
	 *
	 * @param int $exclude_id Episode to leave out (the one being edited).
	 * @return array{all: int, seasons: array<string, int>} Seasons keyed by season number.
	 */
	public static function next_episode_numbers( int $exclude_id = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one aggregate read for the editor screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.meta_value AS num, s.meta_value AS season
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} n ON n.post_id = p.ID AND n.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status IN ('publish', 'future', 'draft', 'pending', 'private')
				AND p.ID <> %d",
				Episodes::META_PREFIX . 'episode_number',
				Episodes::META_PREFIX . 'season_number',
				EpisodePostType::CPT,
				$exclude_id
			),
			ARRAY_A
		);

		$all     = 0;
		$seasons = [];
		foreach ( (array) $rows as $row ) {
			$number = trim( (string) ( $row['num'] ?? '' ) );
			if ( '' === $number || ! ctype_digit( $number ) ) {
				continue;
			}
			$number = (int) $number;
			$all    = max( $all, $number );

			$season = trim( (string) ( $row['season'] ?? '' ) );
			if ( '' !== $season && ctype_digit( $season ) ) {
				$key             = (string) (int) $season;
				$seasons[ $key ] = max( $seasons[ $key ] ?? 0, $number );
			}
		}

		ksort( $seasons, SORT_NUMERIC );

		return [
			'all'     => $all + 1,
			'seasons' => array_map(
				static function ( int $max ): int {
					return $max + 1;
				},
				$seasons
			),
		];
	}

	/**
	 * Guest box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function box_guest( \WP_Post $post ): void {
		?>
		<div class="epm-field-grid">
			<p>
				<label for="epm-guest-name"><?php esc_html_e( 'Guest name', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-guest-name" name="epm[guest_name]" value="<?php echo esc_attr( (string) $this->meta( $post, 'guest_name', '' ) ); ?>" class="widefat" />
			</p>
			<p>
				<label for="epm-guest-role"><?php esc_html_e( 'Role or job title', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-guest-role" name="epm[guest_role]" value="<?php echo esc_attr( (string) $this->meta( $post, 'guest_role', '' ) ); ?>" class="widefat" />
			</p>
			<p>
				<label for="epm-guest-company"><?php esc_html_e( 'Company', 'elementor-podcast-manager' ); ?></label><br />
				<input type="text" id="epm-guest-company" name="epm[guest_company]" value="<?php echo esc_attr( (string) $this->meta( $post, 'guest_company', '' ) ); ?>" class="widefat" />
			</p>
			<p>
				<label for="epm-guest-bio"><?php esc_html_e( 'Guest bio', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></label><br />
				<textarea id="epm-guest-bio" name="epm[guest_bio]" rows="3" class="widefat"><?php echo esc_textarea( (string) $this->meta( $post, 'guest_bio', '' ) ); ?></textarea>
			</p>
		</div>
		<p class="epm-editor-label" id="epm-guest-photo-label"><?php esc_html_e( 'Guest photo', 'elementor-podcast-manager' ); ?></p>
		<?php
		Admin::media_picker(
			[
				'name'        => 'epm[guest_image_id]',
				'key'         => 'guest',
				'id'          => (int) $this->meta( $post, 'guest_image_id', 0 ),
				'choose'      => __( 'Choose guest photo', 'elementor-podcast-manager' ),
				'replace'     => __( 'Replace guest photo', 'elementor-podcast-manager' ),
				'remove'      => __( 'Remove guest photo', 'elementor-podcast-manager' ),
				'frame_title' => __( 'Choose guest photo', 'elementor-podcast-manager' ),
			]
		);
		?>
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
			<textarea id="epm-short-description" name="epm[short_description]" rows="3" class="widefat" aria-describedby="epm-short-description-help"><?php echo esc_textarea( (string) $this->meta( $post, 'short_description', '' ) ); ?></textarea>
			<span class="description" id="epm-short-description-help"><?php esc_html_e( 'Used in lists, cards and the feed summary. Falls back to an excerpt of the full description.', 'elementor-podcast-manager' ); ?></span>
		</p>
		<label class="epm-editor-label" for="epm_show_notes"><?php esc_html_e( 'Show notes', 'elementor-podcast-manager' ); ?></label>
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
		<p class="description"><?php esc_html_e( 'Links, credits and resources for this episode. Shown on the episode page; optional.', 'elementor-podcast-manager' ); ?></p>
		<p>
			<label for="epm-video-url"><?php esc_html_e( 'Video URL', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></label><br />
			<input type="url" id="epm-video-url" name="epm[video_url]" value="<?php echo esc_attr( (string) $this->meta( $post, 'video_url', '' ) ); ?>" class="widefat" inputmode="url" spellcheck="false" aria-describedby="epm-video-url-help" />
			<span class="description" id="epm-video-url-help"><?php esc_html_e( 'YouTube, Vimeo or an MP4 file. Shown as a click-to-play video on the episode page.', 'elementor-podcast-manager' ); ?></span>
		</p>
		<p>
			<label for="epm-youtube-url"><?php esc_html_e( 'YouTube URL', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></label><br />
			<input type="url" id="epm-youtube-url" name="epm[youtube_url]" value="<?php echo esc_attr( (string) $this->meta( $post, 'youtube_url', '' ) ); ?>" class="widefat" inputmode="url" spellcheck="false" aria-describedby="epm-youtube-url-help" />
			<span class="description" id="epm-youtube-url-help"><?php esc_html_e( 'When set, this video is shown instead of the Video URL.', 'elementor-podcast-manager' ); ?></span>
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
		$errors   = self::field_errors( $post->ID );
		$dropped  = is_array( $errors['chapters'] ?? null ) ? $errors['chapters'] : [];
		?>
		<div class="epm-repeat" data-epm-repeat="chapters"<?php echo Admin::repeat_messages( __( 'Chapter added.', 'elementor-podcast-manager' ), __( 'Chapter removed.', 'elementor-podcast-manager' ), /* translators: 1: new position, 2: number of chapters */ __( 'Chapter moved to position %1$d of %2$d.', 'elementor-podcast-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in repeat_messages(). ?>>
			<p class="description" id="epm-chapter-hint"><?php esc_html_e( 'Start times use minutes:seconds (12:30) or hours:minutes:seconds (1:02:30). Listeners can jump to each chapter.', 'elementor-podcast-manager' ); ?></p>
			<div class="epm-repeat__rows" data-epm-repeat-rows>
				<?php foreach ( $chapters as $index => $chapter ) : ?>
					<?php $this->chapter_row( (string) $index, $chapter ); ?>
				<?php endforeach; ?>
				<?php foreach ( array_values( $dropped ) as $index => $chapter ) : ?>
					<?php $this->chapter_row( 'unsaved_' . $index, (array) $chapter, __( 'Not saved yet: add a start time (for example 12:30) and a title, or remove this chapter.', 'elementor-podcast-manager' ) ); ?>
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
			<details class="epm-disclosure epm-paste" data-epm-paste-chapters>
				<summary><?php esc_html_e( 'Paste chapters', 'elementor-podcast-manager' ); ?></summary>
				<div class="epm-paste__body">
					<label class="epm-paste__label" for="epm-paste-chapters-text"><?php esc_html_e( 'Chapter list', 'elementor-podcast-manager' ); ?></label>
					<textarea id="epm-paste-chapters-text" rows="6" class="large-text code" spellcheck="false" autocomplete="off" aria-describedby="epm-paste-chapters-help" data-epm-paste-text></textarea>
					<p class="description" id="epm-paste-chapters-help">
						<?php esc_html_e( 'One chapter per line with the start time first, for example “00:00 Intro”, “12:30 – Listener questions” or “(1:02:03) Wrap-up”. Copy the list from your show notes, a video description or your editing software.', 'elementor-podcast-manager' ); ?>
					</p>
					<p class="epm-paste__replace" data-epm-paste-replace-row hidden>
						<label>
							<input type="checkbox" data-epm-paste-replace />
							<?php esc_html_e( 'Replace the current chapters', 'elementor-podcast-manager' ); ?>
						</label>
					</p>
					<p class="epm-paste__actions">
						<button type="button" class="button" data-epm-paste-apply><?php esc_html_e( 'Add chapters from the list', 'elementor-podcast-manager' ); ?></button>
					</p>
					<div class="epm-paste__result" data-epm-paste-result role="status"></div>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render one chapter row.
	 *
	 * @param string $index   Row key.
	 * @param array  $chapter Chapter data.
	 * @param string $error   Error shown next to the row.
	 * @return void
	 */
	private function chapter_row( string $index, array $chapter, string $error = '' ): void {
		$name     = 'epm[chapters][' . $index . ']';
		$error_id = 'epm-chapters-' . $index . '-error';
		$invalid  = '' !== $error ? ' aria-invalid="true"' : '';
		?>
		<div class="epm-repeat__row<?php echo '' !== $error ? ' is-invalid' : ''; ?>" data-epm-repeat-row>
			<?php Admin::repeat_order_buttons( __( 'Move chapter up', 'elementor-podcast-manager' ), __( 'Move chapter down', 'elementor-podcast-manager' ) ); ?>
			<label class="epm-repeat__field">
				<span class="epm-repeat__label"><?php esc_html_e( 'Start time', 'elementor-podcast-manager' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[time]" value="<?php echo esc_attr( (string) ( $chapter['time'] ?? '' ) ); ?>" class="epm-repeat__time" autocomplete="off" spellcheck="false" pattern="<?php echo esc_attr( self::TIME_PATTERN ); ?>" title="<?php esc_attr_e( 'Use minutes:seconds, for example 12:30', 'elementor-podcast-manager' ); ?>" data-epm-required data-epm-error="<?php esc_attr_e( 'Add a start time, for example 12:30 (minutes:seconds).', 'elementor-podcast-manager' ); ?>" aria-describedby="epm-chapter-hint<?php echo '' !== $error ? ' ' . esc_attr( $error_id ) : ''; ?>"<?php echo $invalid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?> />
			</label>
			<label class="epm-repeat__field">
				<span class="epm-repeat__label"><?php esc_html_e( 'Title', 'elementor-podcast-manager' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( (string) ( $chapter['title'] ?? '' ) ); ?>" autocomplete="off" data-epm-required data-epm-error="<?php esc_attr_e( 'Add a chapter title.', 'elementor-podcast-manager' ); ?>"<?php echo '' !== $error ? ' aria-describedby="' . esc_attr( $error_id ) . '"' : ''; ?> />
			</label>
			<label class="epm-repeat__field">
				<span class="epm-repeat__label"><?php esc_html_e( 'Link', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></span>
				<input type="url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( (string) ( $chapter['url'] ?? '' ) ); ?>" inputmode="url" spellcheck="false" autocomplete="off" />
			</label>
			<?php Admin::repeat_remove_button( __( 'Remove chapter', 'elementor-podcast-manager' ) ); ?>
			<p class="epm-field-error epm-repeat__error" id="<?php echo esc_attr( $error_id ); ?>" data-epm-repeat-error<?php echo '' === $error ? ' hidden' : ''; ?>><?php echo esc_html( $error ); ?></p>
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
		$links    = Episodes::normalize_links( $this->meta( $post, 'platform_urls', [] ) );
		$services = wp_list_pluck( Directories::services(), 'label' );
		$errors   = self::field_errors( $post->ID );
		$dropped  = is_array( $errors['platform_urls'] ?? null ) ? $errors['platform_urls'] : [];
		?>
		<div class="epm-repeat" data-epm-repeat="episode-platforms"<?php echo Admin::repeat_messages( __( 'Link added.', 'elementor-podcast-manager' ), __( 'Link removed.', 'elementor-podcast-manager' ), /* translators: 1: new position, 2: number of links */ __( 'Link moved to position %1$d of %2$d.', 'elementor-podcast-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in repeat_messages(). ?>>
			<p class="description"><?php esc_html_e( 'Optional. When set, these replace the podcast-wide platform links for this episode.', 'elementor-podcast-manager' ); ?></p>
			<div class="epm-repeat__rows" data-epm-repeat-rows>
				<?php foreach ( array_values( $links ) as $index => $link ) : ?>
					<?php Admin::link_row( 'epm[platform_urls][' . $index . ']', 'epm-platform-' . $index, (array) $link, $services ); ?>
				<?php endforeach; ?>
				<?php foreach ( array_values( $dropped ) as $index => $link ) : ?>
					<?php Admin::link_row( 'epm[platform_urls][unsaved_' . $index . ']', 'epm-platform-unsaved-' . $index, (array) $link, $services, __( 'Not saved yet: add the link’s web address, starting with https://, or remove this row.', 'elementor-podcast-manager' ) ); ?>
				<?php endforeach; ?>
			</div>
			<template data-epm-repeat-template>
				<?php Admin::link_row( 'epm[platform_urls][__INDEX__]', 'epm-platform-__INDEX__', [ 'service' => 'custom' ], $services ); ?>
			</template>
			<p>
				<button type="button" class="button" data-epm-repeat-add>
					<?php esc_html_e( 'Add platform link', 'elementor-podcast-manager' ); ?>
				</button>
			</p>
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
		$file_id   = (int) $this->meta( $post, 'transcript_file_id', 0 );
		$file_id   = ( $file_id > 0 && self::is_transcript_attachment( $file_id ) ) ? $file_id : 0;
		$file_url  = $file_id > 0 ? (string) wp_get_attachment_url( $file_id ) : '';
		$file_name = $file_id > 0 ? wp_basename( (string) get_attached_file( $file_id ) ) : '';
		$file_type = $file_id > 0 ? self::transcript_format_label( Transcripts::mime( $file_name ) ) : '';
		$hosted    = esc_url_raw( (string) $this->meta( $post, 'transcript_url', '' ), [ 'http', 'https' ] );
		$errors    = self::field_errors( $post->ID );
		$error     = is_string( $errors['transcript_file'] ?? null ) ? (string) $errors['transcript_file'] : '';
		?>
		<div class="epm-transcript-file" data-epm-transcript-file>
			<p class="epm-editor-label epm-transcript-file__title" id="epm-transcript-file-label"><?php esc_html_e( 'Transcript file', 'elementor-podcast-manager' ); ?> <span class="epm-optional"><?php esc_html_e( '(optional)', 'elementor-podcast-manager' ); ?></span></p>
			<p class="description" id="epm-transcript-file-help"><?php esc_html_e( 'A WebVTT (.vtt) or SubRip (.srt) file. Podcast apps use it for captions and a transcript that follows along with the audio.', 'elementor-podcast-manager' ); ?></p>
			<input type="hidden" name="epm[transcript_file_id]" value="<?php echo esc_attr( $file_id > 0 ? (string) $file_id : '' ); ?>" data-epm-transcript-id />
			<p class="epm-transcript-file__current" data-epm-transcript-current<?php echo $file_id > 0 ? '' : ' hidden'; ?>>
				<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
				<a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener" data-epm-transcript-name><?php echo esc_html( $file_name ); ?></a>
				<span class="epm-transcript-file__type" data-epm-transcript-type><?php echo esc_html( $file_type ); ?></span>
			</p>
			<p class="epm-media__actions">
				<button type="button" class="button" data-epm-transcript-choose aria-describedby="epm-transcript-file-help" data-choose-label="<?php esc_attr_e( 'Choose transcript file', 'elementor-podcast-manager' ); ?>" data-replace-label="<?php esc_attr_e( 'Replace transcript file', 'elementor-podcast-manager' ); ?>"><?php echo $file_id > 0 ? esc_html__( 'Replace transcript file', 'elementor-podcast-manager' ) : esc_html__( 'Choose transcript file', 'elementor-podcast-manager' ); ?></button>
				<button type="button" class="button-link epm-media__remove" data-epm-transcript-remove<?php echo $file_id > 0 ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove transcript file', 'elementor-podcast-manager' ); ?></button>
			</p>
			<p class="epm-field-error" id="epm-transcript-file-error" data-epm-transcript-error<?php echo '' === $error ? ' hidden' : ''; ?>><?php echo esc_html( $error ); ?></p>
			<?php if ( '' !== $hosted ) : ?>
				<?php
				$hosted_mime = Transcripts::normalize_type( (string) $this->meta( $post, 'transcript_type', '' ), $hosted );
				$hosted_type = self::transcript_format_label( $hosted_mime );
				$hosted_type = '' !== $hosted_type ? $hosted_type : __( 'transcript', 'elementor-podcast-manager' );
				$hosted_host = self::host_label( $hosted );
				// Only an address the import wrote came with the import; one
				// set on this site is described as what it is.
				if ( ! Importer::transcript_from_import( $post->ID ) ) {
					/* translators: 1: file format, e.g. WebVTT, 2: host name */
					$hosted_text = __( 'The feed also lists a %1$s transcript hosted at %2$s:', 'elementor-podcast-manager' );
				} elseif ( in_array( $hosted_mime, [ 'text/vtt', 'application/x-subrip' ], true ) ) {
					/* translators: 1: file format, e.g. WebVTT, 2: host name */
					$hosted_text = __( 'The %1$s transcript file from the imported feed is still hosted at %2$s, and the feed links to it there. Importing again with “Copy audio” copies it to this website:', 'elementor-podcast-manager' );
				} else {
					/* translators: 1: file format, e.g. JSON, 2: host name */
					$hosted_text = __( 'The %1$s transcript from the imported feed is hosted at %2$s and stays linked there (this format is not copied):', 'elementor-podcast-manager' );
				}
				?>
				<div class="epm-transcript-file__hosted">
					<p><?php printf( esc_html( $hosted_text ), esc_html( $hosted_type ), esc_html( $hosted_host ) ); ?></p>
					<p class="epm-transcript-file__url"><a href="<?php echo esc_url( $hosted ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $hosted ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'elementor-podcast-manager' ); ?></span></a></p>
				</div>
			<?php endif; ?>
		</div>
		<label class="epm-editor-label" for="epm_transcript"><?php esc_html_e( 'Transcript text', 'elementor-podcast-manager' ); ?></label>
		<?php
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
		echo '<p class="description">' . esc_html__( 'Shown on the episode page and by the Transcript widget. When this is empty and a transcript file is attached, the text is filled from the file when you save.', 'elementor-podcast-manager' ) . '</p>';
	}

	/**
	 * Whether an attachment is a transcript file the feed can list for
	 * captions (WebVTT or SubRip).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_transcript_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$type = Transcripts::mime( (string) get_attached_file( $attachment_id ) );
		if ( '' === $type ) {
			$type = Transcripts::mime( (string) wp_get_attachment_url( $attachment_id ) );
		}

		return in_array( $type, self::transcript_file_types(), true );
	}

	/**
	 * MIME types accepted as transcript files in the editor.
	 *
	 * @return string[]
	 */
	public static function transcript_file_types(): array {
		return [ Transcripts::TYPES['vtt'], Transcripts::TYPES['srt'] ];
	}

	/**
	 * Human name of a transcript format.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function transcript_format_label( string $mime ): string {
		$labels = [
			'text/vtt'             => 'WebVTT',
			'application/x-subrip' => 'SubRip',
			'application/json'     => 'JSON',
			'text/html'            => 'HTML',
			'text/plain'           => __( 'plain text', 'elementor-podcast-manager' ),
		];

		return $labels[ $mime ] ?? '';
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

		// Newly chosen media must be readable or belong to an editable episode. The stored
		// file is not checked again, so another editor can re-save the episode.
		if ( $new_audio_id > 0 && ( ! AudioMetadata::is_valid_audio_attachment( $new_audio_id ) || ( $new_audio_id !== $old_audio_id && ! Capabilities::can_use_attachment( $new_audio_id ) ) ) ) {
			self::add_notice(
				AudioMetadata::is_valid_audio_attachment( $new_audio_id )
					? __( 'You do not have permission to use this audio file. The previous audio association was kept.', 'elementor-podcast-manager' )
					: __( 'The selected audio file is not a supported audio attachment. The previous audio association was kept.', 'elementor-podcast-manager' ),
				'error',
				'epm-audio',
				__( 'Go to the audio', 'elementor-podcast-manager' )
			);
			$new_audio_id = $old_audio_id;
		}

		update_post_meta( $post_id, $p . 'audio_id', $new_audio_id );

		// Manual duration: validate format, keep old value on invalid input.
		$stored_duration = (string) get_post_meta( $post_id, $p . 'duration', true );
		$manual_duration = sanitize_text_field( $input['duration'] ?? '' );
		if ( '' !== $manual_duration && ! AudioMetadata::is_valid_duration( $manual_duration ) ) {
			self::add_notice(
				__( 'The manual duration was not saved: use minutes:seconds (e.g. 48:12) or hours:minutes:seconds.', 'elementor-podcast-manager' ),
				'error',
				'epm-duration',
				__( 'Go to the duration', 'elementor-podcast-manager' )
			);
			self::$field_errors['duration'] = [
				'message' => __( 'Use minutes:seconds (48:12) or hours:minutes:seconds (1:02:30).', 'elementor-podcast-manager' ),
				'value'   => $manual_duration,
			];
			$manual_duration = $stored_duration;
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
						'warning',
						'epm-duration',
						__( 'Go to the duration', 'elementor-podcast-manager' )
					);
				}
			} elseif ( '' === $manual_duration || $manual_duration === $stored_duration ) {
				// The file's duration left with the file; a duration typed
				// in this save (for an audio URL) is kept.
				delete_post_meta( $post_id, $p . 'duration' );
			}
		} elseif ( $new_audio_id > 0 ) {
			// Same attachment: backfill missing derived metadata only. Never blocks publishing.
			AudioMetadata::refresh( $post_id );
		}

		// --- Audio hosted elsewhere. Only when the field was on the form. ---
		if ( array_key_exists( 'audio_url', $input ) ) {
			$this->save_audio_url( $post_id, (string) $input['audio_url'], $new_audio_id > 0 );
		}

		$old_artwork = (int) get_post_meta( $post_id, $p . 'artwork_id', true );
		$new_artwork = absint( $input['artwork_id'] ?? 0 );
		if ( $new_artwork > 0 && ( ! self::is_image_attachment( $new_artwork ) || ( $new_artwork !== $old_artwork && ! Capabilities::can_use_attachment( $new_artwork ) ) ) ) {
			self::add_notice( __( 'The selected artwork must be an image you may use. The previous artwork was kept.', 'elementor-podcast-manager' ), 'error', 'epm-artwork', __( 'Go to the artwork', 'elementor-podcast-manager' ) );
			$new_artwork = $old_artwork;
		}
		update_post_meta( $post_id, $p . 'artwork_id', $new_artwork );

		$old_guest_image = (int) get_post_meta( $post_id, $p . 'guest_image_id', true );
		$new_guest_image = absint( $input['guest_image_id'] ?? 0 );
		if ( $new_guest_image > 0 && ( ! self::is_image_attachment( $new_guest_image ) || ( $new_guest_image !== $old_guest_image && ! Capabilities::can_use_attachment( $new_guest_image ) ) ) ) {
			self::add_notice( __( 'The selected guest photo must be an image you may use. The previous photo was kept.', 'elementor-podcast-manager' ), 'error', 'epm-guest-image', __( 'Go to the guest photo', 'elementor-podcast-manager' ) );
			$new_guest_image = $old_guest_image;
		}
		update_post_meta( $post_id, $p . 'guest_image_id', $new_guest_image );

		update_post_meta( $post_id, $p . 'short_description', sanitize_textarea_field( $input['short_description'] ?? '' ) );
		update_post_meta( $post_id, $p . 'show_notes', wp_kses_post( $input['show_notes'] ?? '' ) );

		// Episode-specific platform links (override/extend the podcast defaults).
		// Rows with text but no usable address are not saved; they come
		// back next to the field with an explanation instead of vanishing.
		$platform_urls = [];
		$dropped_links = [];
		if ( isset( $input['platform_urls'] ) && is_array( $input['platform_urls'] ) ) {
			foreach ( $input['platform_urls'] as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}
				$raw_url = trim( (string) ( $link['url'] ?? '' ) );
				$label   = sanitize_text_field( $link['label'] ?? '' );
				$service = sanitize_key( $link['service'] ?? 'custom' );
				$url     = '' !== $raw_url ? esc_url_raw( $raw_url ) : '';
				if ( '' === $url ) {
					if ( '' !== $raw_url || '' !== $label ) {
						$dropped_links[] = [
							'service' => $service,
							'label'   => $label,
							'url'     => sanitize_text_field( $raw_url ),
						];
					}
					continue;
				}
				$platform_urls[] = [
					'service' => $service,
					'label'   => $label,
					'url'     => $url,
				];
			}
		}
		update_post_meta( $post_id, $p . 'platform_urls', $platform_urls );

		if ( ! empty( $dropped_links ) ) {
			$count = count( $dropped_links );
			self::add_notice(
				sprintf(
					/* translators: %d: number of links */
					_n( '%d platform link was not saved. Each link needs a web address that starts with https://.', '%d platform links were not saved. Each link needs a web address that starts with https://.', $count, 'elementor-podcast-manager' ),
					$count
				),
				'warning',
				'epm-platforms',
				__( 'Go to the links', 'elementor-podcast-manager' )
			);
			self::$field_errors['platform_urls'] = $dropped_links;
		}

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

		$this->save_transcript( $post_id, $input );

		// Chapters. Incomplete rows (no start time or no title) are not
		// saved; they come back next to the field with an explanation.
		$chapters         = [];
		$dropped_chapters = [];
		if ( isset( $input['chapters'] ) && is_array( $input['chapters'] ) ) {
			foreach ( $input['chapters'] as $chapter ) {
				if ( ! is_array( $chapter ) ) {
					continue;
				}
				$time  = sanitize_text_field( $chapter['time'] ?? '' );
				$title = sanitize_text_field( $chapter['title'] ?? '' );
				$url   = esc_url_raw( $chapter['url'] ?? '' );
				if ( '' === $time || '' === $title || ! preg_match( '/^' . self::TIME_PATTERN . '$/', $time ) ) {
					if ( '' !== $time || '' !== $title || '' !== $url ) {
						$dropped_chapters[] = [
							'time'  => $time,
							'title' => $title,
							'url'   => $url,
						];
					}
					continue;
				}
				$chapters[] = [
					'time'  => $time,
					'title' => $title,
					'url'   => $url,
				];
			}
		}
		update_post_meta( $post_id, $p . 'chapters', $chapters );

		if ( ! empty( $dropped_chapters ) ) {
			$count = count( $dropped_chapters );
			self::add_notice(
				sprintf(
					/* translators: %d: number of chapters */
					_n( '%d chapter was not saved. Each chapter needs a start time (for example 12:30) and a title.', '%d chapters were not saved. Each chapter needs a start time (for example 12:30) and a title.', $count, 'elementor-podcast-manager' ),
					$count
				),
				'warning',
				'epm-chapters',
				__( 'Go to the chapters', 'elementor-podcast-manager' )
			);
			self::$field_errors['chapters'] = $dropped_chapters;
		}

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
	 * Whether an ID names an image attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_image_attachment( int $attachment_id ): bool {
		return $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) && 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'image/' );
	}

	/**
	 * REST sanitizer for image attachment IDs.
	 *
	 * @param mixed $value Submitted ID.
	 * @return int
	 */
	public static function sanitize_image_id( $value ): int {
		$id = absint( $value );
		return self::is_image_attachment( $id ) ? $id : 0;
	}

	/**
	 * Save the audio URL (audio hosted elsewhere).
	 *
	 * Empty clears the URL and its size and type. A new address, or one
	 * whose size or type is still unknown, is checked with a HEAD request
	 * to fill _epm_audio_length and _epm_audio_type. An address that is not
	 * http(s) is rejected with an error next to the field; the stored one
	 * stays.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $raw       Submitted value.
	 * @param bool   $has_media Whether a Media Library file is attached (it wins).
	 * @return void
	 */
	private function save_audio_url( int $post_id, string $raw, bool $has_media ): void {
		$p   = Episodes::META_PREFIX;
		$raw = trim( $raw );
		$old = (string) get_post_meta( $post_id, $p . 'audio_url', true );

		if ( '' === $raw ) {
			if ( '' !== $old || '' !== (string) get_post_meta( $post_id, $p . 'audio_type', true ) ) {
				delete_post_meta( $post_id, $p . 'audio_url' );
				delete_post_meta( $post_id, $p . 'audio_type' );
				delete_post_meta( $post_id, $p . 'audio_length' );
			}
			return;
		}

		$url = self::clean_audio_url( $raw );

		if ( '' === $url ) {
			self::add_notice(
				__( 'The audio URL was not saved. Use a web address that starts with https://.', 'elementor-podcast-manager' ),
				'error',
				'epm-audio-url',
				__( 'Go to the audio URL', 'elementor-podcast-manager' )
			);
			self::$field_errors['audio_url'] = [
				'message' => __( 'Enter a web address that starts with https://, for example https://cdn.example.com/episode-12.mp3.', 'elementor-podcast-manager' ),
				'value'   => sanitize_text_field( $raw ),
			];
			return;
		}

		$changed = $url !== $old;
		if ( $changed ) {
			update_post_meta( $post_id, $p . 'audio_url', $url );
			delete_post_meta( $post_id, $p . 'audio_type' );
			delete_post_meta( $post_id, $p . 'audio_length' );
		}

		$length = (int) get_post_meta( $post_id, $p . 'audio_length', true );
		$type   = (string) get_post_meta( $post_id, $p . 'audio_type', true );

		if ( $length > 0 && '' !== $type ) {
			return;
		}

		// Unchanged addresses whose size could not be read are not asked
		// again on every save.
		$recent = 'epm_audio_probe_' . md5( $url );
		if ( ! $changed && get_transient( $recent ) ) {
			return;
		}

		$probe = self::probe_audio_url( $url );

		if ( $length <= 0 && $probe['size'] > 0 ) {
			update_post_meta( $post_id, $p . 'audio_length', (int) $probe['size'] );
		}
		if ( '' === $type ) {
			update_post_meta( $post_id, $p . 'audio_type', (string) $probe['mime'] );
		}
		if ( $probe['size'] <= 0 ) {
			set_transient( $recent, 1, DAY_IN_SECONDS );
		}

		$message = self::probe_message( $probe );
		if ( $changed && '' !== $message && ! $has_media ) {
			self::add_notice(
				/* translators: %s: what went wrong and how to fix it */
				sprintf( __( 'The audio URL was saved. %s', 'elementor-podcast-manager' ), $message ),
				'warning',
				'epm-audio-url',
				__( 'Go to the audio URL', 'elementor-podcast-manager' )
			);
		}
	}

	/**
	 * Save the transcript file and text.
	 *
	 * The file must be a WebVTT or SubRip attachment that the user may read
	 * (checked when the file changes); anything else keeps the stored file
	 * and explains why next to the field. When the text is empty and a file
	 * is attached, the text is filled from the file.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $input   Unslashed form input.
	 * @return void
	 */
	private function save_transcript( int $post_id, array $input ): void {
		$p       = Episodes::META_PREFIX;
		$file_id = (int) get_post_meta( $post_id, $p . 'transcript_file_id', true );

		if ( array_key_exists( 'transcript_file_id', $input ) ) {
			$new_id = absint( $input['transcript_file_id'] );

			// Newly chosen media must be readable or belong to an editable episode: its text
			// is copied into this episode. The stored file is not checked again.
			if ( $new_id > 0 && ( ! self::is_transcript_attachment( $new_id ) || ( $new_id !== $file_id && ! Capabilities::can_use_attachment( $new_id ) ) ) ) {
				$message = self::is_transcript_attachment( $new_id )
					? __( 'You do not have permission to use this transcript file.', 'elementor-podcast-manager' )
					: __( 'The transcript file was not saved. Choose a WebVTT (.vtt) or SubRip (.srt) file.', 'elementor-podcast-manager' );
				self::add_notice(
					$message,
					'error',
					'epm-transcript',
					__( 'Go to the transcript', 'elementor-podcast-manager' )
				);
				self::$field_errors['transcript_file'] = $message;
			} else {
				$file_id = $new_id;
				if ( $file_id > 0 ) {
					update_post_meta( $post_id, $p . 'transcript_file_id', $file_id );
				} else {
					delete_post_meta( $post_id, $p . 'transcript_file_id' );
				}
			}
		}

		$text = wp_kses_post( (string) ( $input['transcript'] ?? '' ) );

		if ( '' === trim( wp_strip_all_tags( $text ) ) && $file_id > 0 && self::is_transcript_attachment( $file_id ) ) {
			$from_file = wp_kses_post( Transcripts::html_from_attachment( $file_id ) );
			if ( '' !== trim( wp_strip_all_tags( $from_file ) ) ) {
				$text = $from_file;
				self::add_notice(
					__( 'The transcript text was filled from the transcript file. You can edit it below.', 'elementor-podcast-manager' ),
					'info',
					'epm-transcript',
					__( 'Go to the transcript', 'elementor-podcast-manager' )
				);
			}
		}

		update_post_meta( $post_id, $p . 'transcript', $text );
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
			wp_send_json_error( [ 'message' => __( 'This file is not audio. Upload an MP3 or M4A file (WAV is kept for storage only).', 'elementor-podcast-manager' ) ] );
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
			wp_send_json_error( [ 'message' => __( 'This file can’t be used as episode audio. Choose an MP3 or M4A file.', 'elementor-podcast-manager' ) ] );
		}
		if ( ! Capabilities::can_use_attachment( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to use this audio file.', 'elementor-podcast-manager' ) ], 403 );
		}

		$info           = AudioMetadata::describe( $attachment_id );
		$info['format'] = self::format_label( (string) $info['mime'] );

		wp_send_json_success( $info );
	}

	/**
	 * AJAX: check an audio URL before saving (reachable, size, format), so
	 * the editor can preview it in place. Nothing is stored.
	 *
	 * @return void
	 */
	public function ajax_audio_url_check(): void {
		check_ajax_referer( 'epm_episode_meta' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( [ 'message' => __( 'You are not allowed to edit this episode.', 'elementor-podcast-manager' ) ], 403 );
			}
		} elseif ( ! Capabilities::can_manage_episodes() ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to manage episodes.', 'elementor-podcast-manager' ) ], 403 );
		}

		$url = self::clean_audio_url( isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in clean_audio_url().

		if ( '' === $url ) {
			wp_send_json_error(
				[
					'message' => __( 'Enter a web address that starts with https://, for example https://cdn.example.com/episode-12.mp3.', 'elementor-podcast-manager' ),
					'field'   => true,
				]
			);
		}

		$probe = self::probe_audio_url( $url );
		$mime  = (string) $probe['mime'];

		wp_send_json_success(
			[
				'url'             => $url,
				'host'            => self::host_label( $url ),
				'mime'            => $mime,
				'format'          => self::format_label( $mime ),
				'size'            => (int) $probe['size'],
				'size_formatted'  => $probe['size'] > 0 ? (string) size_format( (int) $probe['size'] ) : '',
				'is_distribution' => AudioMetadata::is_distribution_format( $mime ),
				'reachable'       => (bool) $probe['ok'],
				'warning'         => self::probe_message( $probe ),
			]
		);
	}

	/**
	 * AJAX: searchable, paginated episode list for the Elementor editor.
	 *
	 * Reaches the whole catalog (no 100-item cap). Only users who can
	 * manage episodes may search. Returns episodes the user could see in
	 * the Episodes list: published, drafts/pending/scheduled (as in core),
	 * and private episodes only when the user may read them.
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
			// Private episodes only for users with read_private_posts, or
			// their own (applies to the search and to "include").
			'perm'           => 'readable',
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
