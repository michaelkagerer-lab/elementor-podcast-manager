<?php
/**
 * Elementor widget: Latest Episode (Layer 3).
 *
 * Source is fixed to "latest". Reuses the single player engine for the
 * player part; a lightweight header card is composed from renderer
 * primitives when the player is hidden.
 *
 * @package EPM
 */

namespace EPM\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LatestEpisodeWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-latest-episode';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Latest Episode', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-star';
	}

	/**
	 * Widget categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return [ 'epm-podcast' ];
	}

	/**
	 * Script dependencies (Elementor asset lifecycle).
	 *
	 * @return array<int, string>
	 */
	public function get_script_depends(): array {
		return [ 'epm-player' ];
	}

	/**
	 * Style dependencies (Elementor asset lifecycle).
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return [ 'epm-frontend' ];
	}

	/**
	 * Search keywords.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return [ 'podcast', 'episode', 'latest', 'newest', 'player', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_latest',
			[
				'label' => __( 'Latest Episode', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'layout',
			[
				'label'   => __( 'Player Layout', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => (string) epm()->design->get( 'default_player_layout' ),
				'options' => [
					'minimal'   => __( 'Minimal', 'elementor-podcast-manager' ),
					'compact'   => __( 'Compact', 'elementor-podcast-manager' ),
					'editorial' => __( 'Editorial', 'elementor-podcast-manager' ),
					'artwork'   => __( 'Artwork', 'elementor-podcast-manager' ),
					'full'      => __( 'Full', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_toggle( 'show_artwork', __( 'Artwork', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_title', __( 'Title', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_description', __( 'Description', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_date', __( 'Date', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_duration', __( 'Duration', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_guest', __( 'Guest', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_player', __( 'Audio Player', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_cta', __( 'Call to Action', 'elementor-podcast-manager' ), false );

		$this->add_control(
			'cta_text',
			[
				'label'     => __( 'CTA Text', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Listen now', 'elementor-podcast-manager' ),
				'condition' => [ 'show_cta' => 'yes' ],
			]
		);

		$this->add_control(
			'cta_url',
			[
				'label'       => __( 'CTA URL', 'elementor-podcast-manager' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => __( 'https://…', 'elementor-podcast-manager' ),
				'condition'   => [ 'show_cta' => 'yes' ],
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_latest',
			[
				'label' => __( 'Latest Episode', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'latest_background', __( 'Background', 'elementor-podcast-manager' ), '--epm-latest-background', '{{WRAPPER}} .epm-latest' );
		$this->add_token_color( 'latest_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-latest' );
		$this->add_token_color( 'latest_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-latest' );
		$this->add_token_color( 'latest_accent', __( 'Accent Color', 'elementor-podcast-manager' ), '--epm-accent', '{{WRAPPER}} .epm-latest' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'latest_title_typography',
				'label'     => __( 'Title', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-latest__title, {{WRAPPER}} .epm-latest .epm-player__title',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'latest_meta_typography',
				'label'     => __( 'Metadata', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-latest .epm-meta',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget.
	 *
	 * @return void
	 */
	protected function render(): void {
		\EPM\Assets::enqueue();

		$settings = $this->get_settings_for_display();
		$episode  = epm()->renderer->resolve_episode( 'latest' );

		if ( ! $episode ) {
			$this->editor_placeholder( __( 'No published episode with audio yet.', 'elementor-podcast-manager' ) );
			return;
		}

		$renderer = epm()->renderer;

		echo '<div class="epm-latest">';

		if ( $this->toggle_on( $settings, 'show_player', true ) ) {
			echo $renderer->player(
				$episode,
				[
					'layout'           => sanitize_key( $settings['layout'] ?? '' ),
					'show_artwork'     => $this->toggle_on( $settings, 'show_artwork', true ),
					'show_title'       => $this->toggle_on( $settings, 'show_title', true ),
					'show_description' => $this->toggle_on( $settings, 'show_description', true ),
					'show_date'        => $this->toggle_on( $settings, 'show_date', true ),
					'show_duration'    => $this->toggle_on( $settings, 'show_duration', true ),
					'show_guest'       => $this->toggle_on( $settings, 'show_guest', true ),
				]
			);
		} else {
			// Header card without player, composed from renderer primitives.
			echo '<div class="epm-latest__header">';

			if ( $this->toggle_on( $settings, 'show_artwork', true ) ) {
				$art = $renderer->artwork( $episode, 'medium', 'epm-latest__artwork-img' );
				if ( '' !== $art ) {
					echo '<div class="epm-latest__artwork">' . $art . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}

			echo '<div class="epm-latest__info">';

			if ( $this->toggle_on( $settings, 'show_title', true ) ) {
				echo '<h3 class="epm-latest__title">' . esc_html( (string) $episode['title'] ) . '</h3>';
			}

			$meta_fields = [];
			if ( $this->toggle_on( $settings, 'show_guest', true ) ) {
				$meta_fields[] = 'guest';
			}
			if ( $this->toggle_on( $settings, 'show_date', true ) ) {
				$meta_fields[] = 'date';
			}
			if ( $this->toggle_on( $settings, 'show_duration', true ) ) {
				$meta_fields[] = 'duration';
			}
			if ( ! empty( $meta_fields ) ) {
				echo $renderer->metadata( $episode, $meta_fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			if ( $this->toggle_on( $settings, 'show_description', true ) ) {
				$desc = '' !== (string) ( $episode['short_description'] ?? '' )
					? (string) $episode['short_description']
					: wp_trim_words( wp_strip_all_tags( (string) ( $episode['description'] ?? '' ) ), 40 );
				if ( '' !== $desc ) {
					echo '<p class="epm-latest__description">' . esc_html( $desc ) . '</p>';
				}
			}

			echo '</div></div>';
		}

		if ( $this->toggle_on( $settings, 'show_cta', false ) ) {
			$cta_url = $settings['cta_url'] ?? [];
			$url     = is_array( $cta_url ) ? (string) ( $cta_url['url'] ?? '' ) : '';
			$text    = sanitize_text_field( $settings['cta_text'] ?? '' );

			if ( '' !== $url && '' !== $text ) {
				$target = ! empty( $cta_url['is_external'] ) ? ' target="_blank"' : '';
				$rel    = ! empty( $cta_url['nofollow'] ) ? ' rel="nofollow"' : '';

				echo '<div class="epm-latest__cta-wrap"><a class="epm-latest__cta" href="' . esc_url( $url ) . '"' . $target . $rel . '>' . esc_html( $text ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- target/rel are hardcoded attribute strings.
			}
		}

		echo '</div>';
	}
}
