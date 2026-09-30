<?php
/**
 * Elementor widget: Podcast Chapters (Layer 3).
 *
 * Renders episode chapters via \EPM\Renderer::chapters(). Clicking a chapter
 * timestamp seeks the active player. Outputs nothing when the episode has
 * no chapters.
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

final class ChaptersWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-chapters';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Chapters', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-number-field';
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
		return [ 'podcast', 'episode', 'chapters', 'timestamps', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_chapters',
			[
				'label' => __( 'Chapters', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'heading',
			[
				'label'   => __( 'Heading', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Chapters', 'elementor-podcast-manager' ),
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_chapters',
			[
				'label' => __( 'Chapters', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'chapters_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-chapters' );
		$this->add_token_color( 'chapters_time', __( 'Timestamp Color', 'elementor-podcast-manager' ), '--epm-accent', '{{WRAPPER}} .epm-chapters' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'chapters_heading_typography',
				'label'     => __( 'Heading', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-chapters__heading',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'chapters_list_typography',
				'label'     => __( 'Chapters', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-chapters__list',
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
		$episode  = $this->resolve_widget_episode( $settings );

		if ( ! $episode ) {
			$this->editor_placeholder( $this->no_episode_message( $settings ) );
			return;
		}

		$html = epm()->renderer->chapters(
			$episode,
			[
				'heading' => sanitize_text_field( $settings['heading'] ?? '' ),
			]
		);

		if ( '' === $html ) {
			$this->editor_placeholder( __( 'This episode has no chapters yet.', 'elementor-podcast-manager' ) );
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
	}
}
