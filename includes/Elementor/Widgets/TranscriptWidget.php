<?php
/**
 * Elementor widget: Podcast Transcript (Layer 3).
 *
 * Renders the episode transcript as semantic, indexable HTML via
 * \EPM\Renderer::transcript(). Outputs nothing when no transcript exists.
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

final class TranscriptWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-transcript';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Transcript', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-text';
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
		return [ 'podcast', 'episode', 'transcript', 'text', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_transcript',
			[
				'label' => __( 'Transcript', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'heading',
			[
				'label'   => __( 'Heading', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Transcript', 'elementor-podcast-manager' ),
			]
		);

		$this->add_heading_tag_control();

		$this->add_control(
			'collapsible',
			[
				'label'        => __( 'Collapsible', 'elementor-podcast-manager' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'elementor-podcast-manager' ),
				'label_off'    => __( 'No', 'elementor-podcast-manager' ),
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_transcript',
			[
				'label' => __( 'Transcript', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'transcript_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-transcript' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'transcript_heading_typography',
				'label'     => __( 'Heading', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-transcript__heading',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'transcript_content_typography',
				'label'     => __( 'Content', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-transcript__content',
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
		$settings = $this->get_settings_for_display();
		$episode  = $this->resolve_widget_episode( $settings );

		if ( ! $episode ) {
			$this->editor_placeholder( $this->no_episode_message( $settings ) );
			return;
		}

		$html = epm()->renderer->transcript(
			$episode,
			[
				'heading'     => sanitize_text_field( $settings['heading'] ?? '' ),
				'heading_tag' => sanitize_key( $settings['heading_tag'] ?? 'h3' ),
				'collapsible' => $this->toggle_on( $settings, 'collapsible', false ),
			]
		);

		if ( '' === $html ) {
			$this->editor_placeholder( __( 'This episode has no transcript yet.', 'elementor-podcast-manager' ) );
			return;
		}

		\EPM\Assets::enqueue_style();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
	}
}
