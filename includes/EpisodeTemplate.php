<?php
/**
 * Automatic episode page content (Layer 2).
 *
 * Makes /podcast/{slug}/ a complete episode page with ANY theme and
 * without Elementor Pro: the player (with its share menu) and the video,
 * if the episode has one, are added above the episode description; topics,
 * guest, show notes, chapters and transcript below it.
 *
 * Designed pages are never touched. Nothing is added when:
 * - "Episode pages → Add player and show notes automatically" is off
 *   (Podcast settings), or the epm_auto_embed filter returns false;
 * - an Elementor Pro Theme Builder single template renders the episode
 *   (the template places the podcast widgets itself);
 * - the episode itself is built with Elementor;
 * - inside the Elementor editor, feeds, REST requests, excerpts or
 *   the_content calls made from wp_head (SEO plugins).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EpisodeTemplate {

	/**
	 * Episode IDs already embedded during this request.
	 *
	 * @var array<int, bool>
	 */
	private array $done = [];

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Priority 20: after wpautop/shortcodes (10) and Elementor's builder
		// content replacement (9), so the decision sees the final content.
		add_filter( 'the_content', [ $this, 'filter_content' ], 20 );
	}

	/**
	 * Whether the current the_content call is the episode page body.
	 *
	 * @param \WP_Post|null $post Global post.
	 * @return bool
	 */
	private function is_episode_body( ?\WP_Post $post ): bool {
		if ( ! $post || EpisodePostType::CPT !== $post->post_type ) {
			return false;
		}

		if ( is_admin() || is_feed() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( ! is_singular( EpisodePostType::CPT ) || (int) get_queried_object_id() !== (int) $post->ID ) {
			return false;
		}

		// SEO plugins build meta descriptions from the_content in wp_head;
		// automatic excerpts run it too. Neither is the page body.
		if ( doing_action( 'wp_head' ) || doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether Elementor is responsible for this episode's layout.
	 *
	 * @param \WP_Post $post Episode.
	 * @return bool
	 */
	private static function is_designed_with_elementor( \WP_Post $post ): bool {
		// Elementor Pro Theme Builder single template is rendering…
		if ( did_action( 'elementor/theme/before_do_single' ) ) {
			return true;
		}

		// …or one applies to this page (checked directly as well, in case the
		// template renders the_content before the action above fired).
		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( method_exists( $module, 'get_conditions_manager' ) ) {
				$conditions = $module->get_conditions_manager();
				if ( method_exists( $conditions, 'get_documents_for_location' ) && ! empty( $conditions->get_documents_for_location( 'single' ) ) ) {
					return true;
				}
			}
		}

		if ( ! epm()->has_elementor() || ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) {
			return true;
		}

		if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
			return true;
		}

		if ( isset( $elementor->documents ) ) {
			$document = $elementor->documents->get( $post->ID );
			if ( $document && method_exists( $document, 'is_built_with_elementor' ) && $document->is_built_with_elementor() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the episode page of this episode gets the podcast components
	 * (also asked early, in wp_enqueue_scripts, so the stylesheet goes into
	 * <head>: see Assets::enqueue_early()).
	 *
	 * @param \WP_Post $post Episode.
	 * @return bool
	 */
	public static function embeds( \WP_Post $post ): bool {
		$enabled = ! empty( epm()->settings->get( 'auto_embed' ) ) && ! self::is_designed_with_elementor( $post );

		/**
		 * Whether to add the player and episode details to the episode page.
		 *
		 * @param bool     $enabled Default decision.
		 * @param \WP_Post $post    Episode.
		 */
		return (bool) apply_filters( 'epm_auto_embed', $enabled, $post );
	}

	/**
	 * Resolve episode data for the page being viewed.
	 *
	 * The page is already visible to this visitor (WordPress resolved it),
	 * so drafts in preview and unlocked password-protected episodes render
	 * too; the password form stays untouched until the visitor unlocks it.
	 *
	 * @param \WP_Post $post Episode.
	 * @return array<string, mixed>|null
	 */
	private static function episode_data( \WP_Post $post ): ?array {
		if ( post_password_required( $post ) ) {
			return null;
		}

		$data = epm()->episodes->get_public_data( $post );

		if ( ! $data && ( '' !== $post->post_password || Episodes::can_preview( $post ) ) ) {
			$data = epm()->episodes->get_data( $post );
		}

		return $data;
	}

	/**
	 * Whether the visitor gets the podcast components on this episode's
	 * page now (the components are on, and the episode is visible to them).
	 *
	 * @param \WP_Post $post Episode.
	 * @return bool
	 */
	public static function will_render( \WP_Post $post ): bool {
		return self::embeds( $post ) && null !== self::episode_data( $post );
	}

	/**
	 * Player configuration for the episode page.
	 *
	 * Details: Podcast → Design → Details shown by default → Episode pages,
	 * else the built-in defaults (Details::neutral( 'episode_page' )). The
	 * title is never shown: the theme prints it as the page heading.
	 *
	 * Layout: the site's default player layout (Podcast → Design), except
	 * that Minimal and Compact — which hide speed, volume, download, share
	 * and the description — become Full, so listeners get every control.
	 * When the details hide all of those, there is nothing to make room
	 * for and the chosen layout stays.
	 *
	 * The epm_auto_embed_player_args filter runs last and can change
	 * anything (layout, details, sticky).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<string, mixed>
	 */
	public static function player_args( array $episode ): array {
		$args = Details::resolve( 'episode_page' );

		$layout = (string) epm()->design->get( 'default_player_layout' );
		if ( in_array( $layout, [ 'minimal', 'compact' ], true ) ) {
			$hidden_by_layout = [ 'show_playback_speed', 'show_volume', 'show_download', 'show_share', 'show_description' ];
			foreach ( $hidden_by_layout as $flag ) {
				if ( ! empty( $args[ $flag ] ) ) {
					$layout = 'full';
					break;
				}
			}
		}

		$args = array_merge(
			$args,
			[
				'layout'             => $layout,
				// The theme already prints the episode title as the page heading.
				'show_title'         => false,
				// Pause and seek stay at hand while reading the show notes
				// and transcript below (hidden until something plays).
				'sticky'             => true,
			]
		);

		/**
		 * Player arguments of the automatic episode page, after the
		 * details were resolved (see Renderer::player()).
		 *
		 * @param array $args    Player arguments.
		 * @param array $episode Episode data.
		 */
		return (array) apply_filters( 'epm_auto_embed_player_args', $args, $episode );
	}

	/**
	 * Add the podcast components around the episode description.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ): string {
		$content = (string) $content;
		$post    = get_post();

		if ( ! $this->is_episode_body( $post ) || isset( $this->done[ $post->ID ] ) ) {
			return $content;
		}

		if ( ! self::embeds( $post ) ) {
			return $content;
		}

		$episode = self::episode_data( $post );
		if ( ! $episode ) {
			return $content;
		}

		$this->done[ $post->ID ] = true;

		$renderer = epm()->renderer;

		/**
		 * Components added to the episode page, in order. Use "content" for
		 * the episode description. Remove or reorder entries to customize.
		 *
		 * @param string[] $parts   Component keys.
		 * @param array    $episode Episode data.
		 */
		$parts = (array) apply_filters(
			'epm_auto_embed_parts',
			[ 'player', 'video', 'content', 'topics', 'guest', 'show_notes', 'chapters', 'transcript' ],
			$episode
		);

		// The theme prints the episode title as the page's H1, so section
		// headings continue the outline at H2.
		$section = [ 'heading_tag' => 'h2' ];

		$html = '';
		foreach ( $parts as $part ) {
			switch ( $part ) {
				case 'player':
					$html .= $renderer->player( $episode, self::player_args( $episode ) );
					break;
				case 'video':
					// Click-to-load: nothing loads from the platform before play.
					$html .= $renderer->video( $episode );
					break;
				case 'content':
					$html .= $content;
					break;
				case 'topics':
					$html .= $renderer->topics( $episode );
					break;
				case 'guest':
					$html .= $renderer->guest(
						$episode,
						$section + [
							'show_bio' => true,
							'heading'  => __( 'Guest', 'elementor-podcast-manager' ),
						]
					);
					break;
				case 'show_notes':
					$html .= $renderer->show_notes( $episode, $section );
					break;
				case 'chapters':
					$html .= $renderer->chapters( $episode, $section );
					break;
				case 'transcript':
					$html .= $renderer->transcript( $episode, $section + [ 'collapsible' => true ] );
					break;
			}
		}

		Assets::enqueue();

		return '<div class="epm-episode-page">' . $html . '</div>';
	}
}
