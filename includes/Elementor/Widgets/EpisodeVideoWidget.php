<?php
/**
 * Elementor widget: Episode Video (Layer 3).
 *
 * Renders the episode's video (YouTube, Vimeo or a video file) through
 * \EPM\Renderer::video() as a privacy-friendly click-to-load facade: the
 * episode artwork and a play button until the visitor starts it, so no
 * request reaches the video platform before that. Outputs nothing when the
 * episode has no video.
 *
 * @package EPM
 */

namespace EPM\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EpisodeVideoWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-episode-video';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Episode Video', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-youtube';
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
	 * Script dependencies (the player engine loads the video on play).
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
		return [ 'podcast', 'episode', 'video', 'youtube', 'vimeo', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_video',
			[
				'label' => __( 'Video', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_toggle(
			'show_note',
			__( 'Privacy Note', 'elementor-podcast-manager' ),
			true,
			[ 'description' => __( 'Tells visitors which platform the video loads from once they play it.', 'elementor-podcast-manager' ) ]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_video',
			[
				'label' => __( 'Video', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		// At rest the play button is neutral glass, readable on any artwork;
		// these colors show while the pointer is over the video.
		$this->add_token_color( 'video_accent', __( 'Play Button Hover Color', 'elementor-podcast-manager' ), '--epm-accent', '{{WRAPPER}} .epm-video' );
		$this->add_token_color( 'video_on_accent', __( 'Play Icon Hover Color', 'elementor-podcast-manager' ), '--epm-on-accent', '{{WRAPPER}} .epm-video' );

		$this->add_responsive_control(
			'video_radius',
			[
				'label'      => __( 'Border Radius', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 48 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-video' => '--epm-radius: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
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

		$html = epm()->renderer->video( $episode, [ 'show_note' => $this->toggle_on( $settings, 'show_note', true ) ] );

		if ( '' === $html ) {
			$this->editor_placeholder( __( 'This episode has no video. Add a YouTube or video address in the episode editor.', 'elementor-podcast-manager' ) );
			return;
		}

		\EPM\Assets::enqueue();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
	}
}
