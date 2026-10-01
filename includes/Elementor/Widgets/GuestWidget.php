<?php
/**
 * Elementor widget: Podcast Guest (Layer 3).
 *
 * Renders guest information via \EPM\Renderer::guest(). Outputs nothing
 * when the episode has no guest data.
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

final class GuestWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-guest';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Guest', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-user';
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
		return [ 'podcast', 'episode', 'guest', 'interview', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_guest',
			[
				'label' => __( 'Guest', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_toggle( 'show_image', __( 'Image', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_role', __( 'Role', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_company', __( 'Company', 'elementor-podcast-manager' ), true );
		$this->add_toggle( 'show_bio', __( 'Bio', 'elementor-podcast-manager' ), false );

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_guest',
			[
				'label' => __( 'Guest', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'guest_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-guest' );
		$this->add_token_color( 'guest_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-guest' );

		$this->add_responsive_control(
			'guest_image_size',
			[
				'label'      => __( 'Image Size', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 32, 'max' => 240 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-guest' => '--epm-guest-image-size: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'guest_name_typography',
				'label'     => __( 'Name', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-guest__name',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'guest_detail_typography',
				'label'     => __( 'Role / Company', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-guest__role, {{WRAPPER}} .epm-guest__company',
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

		$html = epm()->renderer->guest(
			$episode,
			[
				'show_image'   => $this->toggle_on( $settings, 'show_image', true ),
				'show_role'    => $this->toggle_on( $settings, 'show_role', true ),
				'show_company' => $this->toggle_on( $settings, 'show_company', true ),
				'show_bio'     => $this->toggle_on( $settings, 'show_bio', false ),
			]
		);

		if ( '' === $html ) {
			$this->editor_placeholder( __( 'This episode has no guest. Add guest details in the episode editor.', 'elementor-podcast-manager' ) );
			return;
		}

		\EPM\Assets::enqueue_style();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
	}
}
