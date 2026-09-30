<?php
/**
 * Elementor widget: Podcast Hero (Layer 3).
 *
 * Large podcast header composed from podcast settings + renderer
 * primitives (artwork, subscribe links). Layout select maps to
 * epm-podcast-hero--{layout} modifiers; artwork-left is the default
 * and carries no modifier class.
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

final class PodcastHeroWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-podcast-hero';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Hero', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-header';
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
		return [ 'podcast', 'hero', 'header', 'subscribe', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_hero',
			[
				'label' => __( 'Hero', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'hero_layout',
			[
				'label'   => __( 'Layout', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'artwork-left',
				'options' => [
					'artwork-left'  => __( 'Artwork Left', 'elementor-podcast-manager' ),
					'artwork-right' => __( 'Artwork Right', 'elementor-podcast-manager' ),
					'centered'      => __( 'Centered', 'elementor-podcast-manager' ),
					'editorial'     => __( 'Editorial', 'elementor-podcast-manager' ),
					'minimal'       => __( 'Minimal', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_toggle( 'show_artwork', __( 'Artwork', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_title', __( 'Title', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_description', __( 'Description', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_host', __( 'Host', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_cta', __( 'Call to Action', 'elementor-podcast-manager' ), false );
		$this->add_toggle( 'show_platform_links', __( 'Platform Links', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_rss', __( 'RSS Link', 'elementor-podcast-manager' ), true );

		$this->add_control(
			'cta_text',
			[
				'label'     => __( 'CTA Text', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Latest episode', 'elementor-podcast-manager' ),
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

		$this->add_control(
			'links_display',
			[
				'label'   => __( 'Platform Links Display', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'icon-text',
				'options' => [
					'icon'      => __( 'Icons', 'elementor-podcast-manager' ),
					'text'      => __( 'Text', 'elementor-podcast-manager' ),
					'icon-text' => __( 'Icons + Text', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_hero',
			[
				'label' => __( 'Hero', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'hero_background', __( 'Background', 'elementor-podcast-manager' ), '--epm-hero-background', '{{WRAPPER}} .epm-podcast-hero' );
		$this->add_token_color( 'hero_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-podcast-hero' );
		$this->add_token_color( 'hero_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-podcast-hero' );
		$this->add_token_color( 'hero_accent', __( 'Accent Color', 'elementor-podcast-manager' ), '--epm-accent', '{{WRAPPER}} .epm-podcast-hero' );

		$this->add_responsive_control(
			'hero_gap',
			[
				'label'      => __( 'Gap', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 120 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-podcast-hero' => '--epm-gap: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'hero_title_typography',
				'label'     => __( 'Title', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-podcast-hero__title',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'hero_description_typography',
				'label'     => __( 'Description', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-podcast-hero__description',
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
		\EPM\Assets::enqueue_style();

		$settings = $this->get_settings_for_display();
		$podcast  = epm()->settings;
		$renderer = epm()->renderer;

		$layout  = sanitize_key( $settings['hero_layout'] ?? 'artwork-left' );
		$classes = [ 'epm-podcast-hero' ];
		if ( 'artwork-left' !== $layout ) {
			$classes[] = 'epm-podcast-hero--' . $layout;
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( $this->toggle_on( $settings, 'show_artwork', true ) ) {
			$art = $renderer->artwork(
				[
					'artwork_id' => (int) $podcast->get( 'artwork_id' ),
					'title'      => (string) $podcast->get( 'title' ),
				],
				'large',
				'epm-podcast-hero__artwork-img'
			);

			if ( '' !== $art ) {
				echo '<div class="epm-podcast-hero__artwork">' . $art . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		echo '<div class="epm-podcast-hero__content">';

		if ( $this->toggle_on( $settings, 'show_title', true ) ) {
			$title = (string) $podcast->get( 'title' );
			if ( '' !== $title ) {
				echo '<h2 class="epm-podcast-hero__title">' . esc_html( $title ) . '</h2>';
			}
		}

		if ( $this->toggle_on( $settings, 'show_host', true ) ) {
			$host = (string) $podcast->get( 'host' );
			if ( '' !== $host ) {
				echo '<p class="epm-podcast-hero__host">' . esc_html( $host ) . '</p>';
			}
		}

		if ( $this->toggle_on( $settings, 'show_description', true ) ) {
			$description = (string) $podcast->get( 'description' );
			if ( '' !== $description ) {
				echo '<div class="epm-podcast-hero__description">' . wp_kses_post( $description ) . '</div>';
			}
		}

		echo '<div class="epm-podcast-hero__actions">';

		if ( $this->toggle_on( $settings, 'show_cta', false ) ) {
			$cta_url = $settings['cta_url'] ?? [];
			$url     = is_array( $cta_url ) ? (string) ( $cta_url['url'] ?? '' ) : '';
			$text    = sanitize_text_field( $settings['cta_text'] ?? '' );

			if ( '' !== $url && '' !== $text ) {
				$target = ! empty( $cta_url['is_external'] ) ? ' target="_blank"' : '';
				$rel    = ! empty( $cta_url['nofollow'] ) ? ' rel="nofollow"' : '';

				echo '<a class="epm-podcast-hero__cta" href="' . esc_url( $url ) . '"' . $target . $rel . '>' . esc_html( $text ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- target/rel are hardcoded attribute strings.
			}
		}

		if ( $this->toggle_on( $settings, 'show_platform_links', true ) ) {
			echo $renderer->subscribe_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(array) $podcast->get( 'platform_links' ),
				[
					'display'  => sanitize_key( $settings['links_display'] ?? 'icon-text' ),
					'show_rss' => $this->toggle_on( $settings, 'show_rss', true ),
				]
			);
		}

		echo '</div></div></div>';
	}
}
