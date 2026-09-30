<?php
/**
 * Elementor widget: Podcast Subscribe Links (Layer 3).
 *
 * Platform links come from podcast settings; the RSS link is appended by
 * the renderer. Display mode (icon / text / icon + text) is styling-neutral:
 * no platform brand colors are forced.
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

final class SubscribeLinksWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-subscribe-links';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Subscribe Links', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-share';
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
		return [ 'podcast', 'subscribe', 'platforms', 'spotify', 'apple', 'rss', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_subscribe',
			[
				'label' => __( 'Subscribe Links', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'display',
			[
				'label'   => __( 'Display', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'icon-text',
				'options' => [
					'icon'      => __( 'Icons', 'elementor-podcast-manager' ),
					'text'      => __( 'Text', 'elementor-podcast-manager' ),
					'icon-text' => __( 'Icons + Text', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_toggle( 'show_rss', __( 'RSS Link', 'elementor-podcast-manager' ), true );

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_subscribe',
			[
				'label' => __( 'Subscribe Links', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'subscribe_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-subscribe' );
		$this->add_token_color( 'subscribe_accent', __( 'Hover Color', 'elementor-podcast-manager' ), '--epm-subscribe-hover', '{{WRAPPER}} .epm-subscribe' );

		$this->add_responsive_control(
			'subscribe_icon_size',
			[
				'label'      => __( 'Icon Size', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 12, 'max' => 48 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-subscribe' => '--epm-subscribe-icon-size: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'subscribe_gap',
			[
				'label'      => __( 'Gap', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-subscribe' => '--epm-subscribe-gap: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'subscribe_typography',
				'label'     => __( 'Label', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-subscribe__label',
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

		echo epm()->renderer->subscribe_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(array) epm()->settings->get( 'platform_links' ),
			[
				'display'  => sanitize_key( $settings['display'] ?? 'icon-text' ),
				'show_rss' => $this->toggle_on( $settings, 'show_rss', true ),
			]
		);
	}
}
