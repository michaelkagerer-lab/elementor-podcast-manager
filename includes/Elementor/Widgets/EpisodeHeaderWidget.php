<?php
/**
 * Elementor widget: Episode Header (Layer 3).
 *
 * Reusable episode header composed from renderer primitives. Inside an
 * Elementor Theme Builder Single Podcast Episode template the "Current
 * Episode" source resolves automatically for every episode.
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

final class EpisodeHeaderWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-episode-header';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Episode Header', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-heading';
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
		return [ 'podcast', 'episode', 'header', 'title', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_header',
			[
				'label' => __( 'Header', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_toggle( 'show_label', __( 'Episode Label', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_episode_number', __( 'Episode Number in Label', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_season', __( 'Season in Label', 'elementor-podcast-manager' ), false );
		$this->add_toggle( 'show_title', __( 'Title', 'elementor-podcast-manager' ), true );
		$this->add_title_tag_control( 'h1' );
		$this->add_toggle( 'show_guest', __( 'Guest', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_date', __( 'Date', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_artwork', __( 'Artwork', 'elementor-podcast-manager' ), false );
		$this->add_toggle( 'show_description', __( 'Description', 'elementor-podcast-manager' ), false );

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_header',
			[
				'label' => __( 'Header', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'header_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-episode-header' );
		$this->add_token_color( 'header_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-episode-header' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'header_label_typography',
				'label'     => __( 'Label', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-episode-header__label',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'header_title_typography',
				'label'     => __( 'Title', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-episode-header__title',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'header_meta_typography',
				'label'     => __( 'Metadata', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-episode-header .epm-meta',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();
		$this->add_task_guidance();
	}

	/**
	 * Render the widget.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$episode  = $this->resolve_widget_episode( $settings );

		if ( ! $episode ) {
			$this->editor_placeholder( $this->no_episode_message( $settings ) );
			return;
		}

		\EPM\Assets::enqueue_style();

		$renderer = epm()->renderer;

		echo '<header class="epm-episode-header">';

		if ( $this->toggle_on( $settings, 'show_artwork', false ) ) {
			$art = $renderer->artwork( $episode, 'medium', 'epm-episode-header__artwork-img' );
			if ( '' !== $art ) {
				echo '<div class="epm-episode-header__artwork">' . $art . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		if ( $this->toggle_on( $settings, 'show_label', true ) ) {
			$label = $renderer->episode_label(
				$episode,
				$this->toggle_on( $settings, 'show_episode_number', true ),
				$this->toggle_on( $settings, 'show_season', false )
			);

			if ( '' !== $label ) {
				echo '<p class="epm-episode-header__label">' . $label . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
			}
		}

		if ( $this->toggle_on( $settings, 'show_title', true ) ) {
			$tag = $this->title_tag( $settings, 'h1' );
			echo '<' . $tag . ' class="epm-episode-header__title">' . esc_html( (string) $episode['title'] ) . '</' . $tag . '>';
		}

		$meta_fields = [];
		if ( $this->toggle_on( $settings, 'show_guest', true ) ) {
			$meta_fields[] = 'guest';
		}
		if ( $this->toggle_on( $settings, 'show_date', true ) ) {
			$meta_fields[] = 'date';
		}
		if ( ! empty( $meta_fields ) ) {
			echo $renderer->metadata( $episode, $meta_fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $this->toggle_on( $settings, 'show_description', false ) ) {
			$desc = '' !== (string) ( $episode['short_description'] ?? '' )
				? (string) $episode['short_description']
				: wp_trim_words( wp_strip_all_tags( (string) ( $episode['description'] ?? '' ) ), 55 );

			if ( '' !== $desc ) {
				echo '<div class="epm-episode-header__description">' . esc_html( $desc ) . '</div>';
			}
		}

		echo '</header>';
	}
}
