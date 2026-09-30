<?php
/**
 * Automatic episode page content (Layer 2).
 *
 * Makes /podcast/{slug}/ a complete episode page with ANY theme and
 * without Elementor Pro: the player is added above the episode
 * description, and guest, show notes, chapters and transcript below it.
 *
 * Designed pages are never touched. Nothing is added when:
 * - "Episode pages → Add player and show notes automatically" is off
 *   (Podcast Settings), or the epm_auto_embed filter returns false;
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
	private function is_designed_with_elementor( \WP_Post $post ): bool {
		// Elementor Pro Theme Builder single template is rendering.
		if ( did_action( 'elementor/theme/before_do_single' ) ) {
			return true;
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
	 * Resolve episode data for the page being viewed.
	 *
	 * The page is already visible to this visitor (WordPress resolved it),
	 * so drafts in preview and unlocked password-protected episodes render
	 * too; the password form stays untouched until the visitor unlocks it.
	 *
	 * @param \WP_Post $post Episode.
	 * @return array<string, mixed>|null
	 */
	private function episode_data( \WP_Post $post ): ?array {
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
	 * Player configuration for the episode page.
	 *
	 * Uses the site's default player layout (Podcast → Design), except that
	 * the Minimal and Compact layouts — which hide speed and volume — are
	 * upgraded to Full: on the episode page listeners need every control.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<string, mixed>
	 */
	private function player_args( array $episode ): array {
		$layout = (string) epm()->design->get( 'default_player_layout' );
		if ( in_array( $layout, [ 'minimal', 'compact' ], true ) ) {
			$layout = 'full';
		}

		$args = [
			'layout'              => $layout,
			// The theme already prints the episode title as the page heading.
			'show_title'          => false,
			'show_episode_number' => true,
			'show_date'           => true,
			'show_description'    => false,
			'show_chapters_link'  => false,
			'show_download'       => true,
		];

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

		$enabled = ! empty( epm()->settings->get( 'auto_embed' ) ) && ! $this->is_designed_with_elementor( $post );

		/**
		 * Whether to add the player and episode details to the episode page.
		 *
		 * @param bool     $enabled Default decision.
		 * @param \WP_Post $post    Episode.
		 */
		if ( ! apply_filters( 'epm_auto_embed', $enabled, $post ) ) {
			return $content;
		}

		$episode = $this->episode_data( $post );
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
			[ 'player', 'content', 'guest', 'show_notes', 'chapters', 'transcript' ],
			$episode
		);

		$html = '';
		foreach ( $parts as $part ) {
			switch ( $part ) {
				case 'player':
					$html .= $renderer->player( $episode, $this->player_args( $episode ) );
					break;
				case 'content':
					$html .= $content;
					break;
				case 'guest':
					$html .= $renderer->guest( $episode, [ 'show_bio' => true ] );
					break;
				case 'show_notes':
					$html .= $renderer->show_notes( $episode );
					break;
				case 'chapters':
					$html .= $renderer->chapters( $episode );
					break;
				case 'transcript':
					$html .= $renderer->transcript( $episode, [ 'collapsible' => true ] );
					break;
			}
		}

		Assets::enqueue();

		return '<div class="epm-episode-page">' . $html . '</div>';
	}
}
