<?php
/**
 * Episode embeds (Layer 2).
 *
 * /podcast/{slug}/embed/ is the address WordPress loads in an iframe when an
 * episode URL is pasted into another WordPress site (oEmbed). Instead of the
 * generic post card it shows a compact, playable player card: artwork, show
 * name, episode title linking back to the episode page, and the site's
 * podcast design. Only the podcast stylesheet, the player engine and a small
 * bridge script (height and link messages for the embedding site) load
 * inside the frame; the theme never does.
 *
 * The share menu's "Copy embed code" copies an <iframe> for the same
 * address (see code()), so the card also works on sites without WordPress.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Embed {

	/**
	 * Frame height of the card in pixels. WordPress embeds never shrink a
	 * frame below 200px, so the card is designed for exactly that height.
	 */
	public const HEIGHT = 200;

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// After redirect_canonical (10), before the template loader.
		add_action( 'template_redirect', [ $this, 'maybe_render' ], 20 );
		// After core's get_oembed_response_data_rich() (10), which builds
		// the iframe HTML from its own height.
		add_filter( 'oembed_response_data', [ $this, 'response_data' ], 11, 2 );
	}

	/**
	 * Whether this request is the embed view of a podcast episode.
	 *
	 * @return bool
	 */
	public static function is_episode_embed(): bool {
		return is_embed() && is_singular( EpisodePostType::CPT );
	}

	/**
	 * The <iframe> code that embeds an episode on any website.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return string Empty when the episode cannot be embedded (not public).
	 */
	public static function code( array $episode ): string {
		$id = (int) ( $episode['id'] ?? 0 );

		// is_post_embeddable() exists since WordPress 6.8.
		if ( $id <= 0 || ! Episodes::is_publicly_visible( $id ) || ( function_exists( 'is_post_embeddable' ) && ! is_post_embeddable( $id ) ) ) {
			return '';
		}

		$url = get_post_embed_url( $id );
		if ( ! $url ) {
			return '';
		}

		$show  = self::show_title();
		$title = wp_strip_all_tags( (string) ( $episode['title'] ?? '' ) );
		if ( '' !== $show ) {
			/* translators: 1: episode title, 2: podcast title */
			$title = sprintf( __( '%1$s – %2$s', 'elementor-podcast-manager' ), $title, $show );
		}

		return sprintf(
			'<iframe src="%1$s" title="%2$s" width="640" height="%3$d" style="width:100%%;max-width:640px;border:0" loading="lazy"></iframe>',
			esc_url( $url ),
			esc_attr( $title ),
			self::HEIGHT
		);
	}

	/**
	 * Podcast title shown on the card (the site title as a fallback).
	 *
	 * @return string
	 */
	public static function show_title(): string {
		$title = trim( wp_strip_all_tags( (string) epm()->settings->get( 'title' ) ) );

		return '' !== $title ? $title : (string) get_bloginfo( 'name' );
	}

	/**
	 * oEmbed response: announce the card's height, and build the iframe
	 * HTML with it, so the embedding site sizes the frame right before the
	 * first height message arrives (and consumers that ignore the message
	 * never keep an oversized frame).
	 *
	 * @param array    $data Response data.
	 * @param \WP_Post $post Embedded post.
	 * @return array
	 */
	public function response_data( $data, $post ) {
		if ( is_array( $data ) && $post instanceof \WP_Post && EpisodePostType::CPT === $post->post_type ) {
			$data['height'] = self::HEIGHT;
			if ( isset( $data['html'] ) ) {
				$html = get_post_embed_html( (int) ( $data['width'] ?? 600 ), self::HEIGHT, $post );
				if ( is_string( $html ) && '' !== $html ) {
					$data['html'] = $html;
				}
			}
		}

		return $data;
	}

	/**
	 * Render the card for an episode embed and stop. Anything else (other
	 * post types, password-protected or unpublished episodes) keeps the
	 * WordPress embed template.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( ! self::is_episode_embed() ) {
			return;
		}

		$post    = get_queried_object();
		$episode = $post instanceof \WP_Post ? epm()->episodes->get_public_data( $post ) : null;

		if ( ! $episode || empty( $episode['has_audio'] ) ) {
			return;
		}

		// Framed on purpose: other sites load this page in an iframe.
		header( 'X-Robots-Tag: noindex, follow' );

		echo $this->document( $episode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/**
	 * Player arguments of the embed card.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<string, mixed>
	 */
	public function player_args( array $episode ): array {
		$args = [
			'layout'              => 'compact',
			'class'               => 'epm-player--embed',
			'show_artwork'        => true,
			'show_episode_label'  => true,
			'label'               => self::show_title(),
			'show_title'          => true,
			'title_url'           => (string) ( $episode['url'] ?? '' ),
			'show_guest'          => false,
			'show_date'           => false,
			'show_duration'       => true,
			'show_skip_backward'  => true,
			'show_skip_forward'   => true,
			'show_playback_speed' => false,
			'show_volume'         => false,
			'show_download'       => false,
			'show_share'          => false,
			'sticky'              => false,
		];

		/**
		 * Player arguments of the episode embed card.
		 *
		 * @param array $args    Player arguments (see Renderer::player()).
		 * @param array $episode Episode data.
		 */
		return (array) apply_filters( 'epm_embed_player_args', $args, $episode );
	}

	/**
	 * The complete embed document: podcast styles and player only.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return string
	 */
	public function document( array $episode ): string {
		$player = epm()->renderer->player( $episode, $this->player_args( $episode ) );

		$title = sprintf(
			/* translators: 1: episode title, 2: podcast title */
			__( '%1$s – %2$s', 'elementor-podcast-manager' ),
			wp_strip_all_tags( (string) ( $episode['title'] ?? '' ) ),
			self::show_title()
		);

		$body_class = 'epm-embed-page';
		if ( '' !== ( DesignSettings::font_stacks()[ (string) epm()->design->get( 'font_family' ) ] ?? '' ) ) {
			$body_class .= ' epm-custom-font';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, follow" />
<title><?php echo esc_html( $title ); ?></title>
<link rel="canonical" href="<?php echo esc_url( (string) $episode['url'] ); ?>" />
		<?php
		epm()->design->output_tokens();
		wp_print_styles( [ 'epm-frontend' ] );
		?>
</head>
<body class="<?php echo esc_attr( $body_class ); ?>">
<main class="epm-embed">
		<?php echo $player; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped. ?>
</main>
		<?php wp_print_scripts( [ 'epm-player', 'epm-embed' ] ); ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
