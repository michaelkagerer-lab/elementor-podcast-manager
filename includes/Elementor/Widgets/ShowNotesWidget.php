<?php
/**
 * Elementor widget: Podcast Show Notes (Layer 3).
 *
 * Renders the episode's rich show notes as semantic HTML via
 * \EPM\Renderer::show_notes(). Outputs nothing when no show notes exist.
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

final class ShowNotesWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-show-notes';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Show Notes', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-notes';
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
		return [ 'podcast', 'episode', 'show notes', 'text', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_show_notes',
			[
				'label' => __( 'Show Notes', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'heading',
			[
				'label'   => __( 'Heading', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Show Notes', 'elementor-podcast-manager' ),
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_show_notes',
			[
				'label' => __( 'Show Notes', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'show_notes_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-show-notes' );
		$this->add_token_color( 'show_notes_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-show-notes' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'show_notes_heading_typography',
				'label'     => __( 'Heading', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-show-notes__heading',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'show_notes_content_typography',
				'label'     => __( 'Content', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-show-notes__content',
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
		$episode  = $this->resolve_widget_episode( $settings );

		if ( ! $episode ) {
			$this->editor_placeholder( $this->no_episode_message( $settings ) );
			return;
		}

		$html = epm()->renderer->show_notes(
			$episode,
			[
				'heading' => sanitize_text_field( $settings['heading'] ?? '' ),
			]
		);

		if ( '' === $html ) {
			$this->editor_placeholder( __( 'This episode has no show notes yet.', 'elementor-podcast-manager' ) );
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
	}
}
