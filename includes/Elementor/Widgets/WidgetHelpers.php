<?php
/**
 * Shared control helpers for podcast widgets (Layer 3).
 *
 * Keeps the ten widgets DRY: episode source section, published-episode
 * options, switcher shorthand, and the "Global Podcast Styles vs Custom"
 * styling pattern that maps controls onto --epm-* CSS variables.
 *
 * @package EPM
 */

namespace EPM\Elementor\Widgets;

use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait WidgetHelpers {

	/**
	 * Content section: episode source (current / specific / latest).
	 *
	 * @param string $default Default source.
	 * @return void
	 */
	protected function add_episode_source_section( string $default = 'current' ): void {
		$this->start_controls_section(
			'section_episode_source',
			[
				'label' => __( 'Episode', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'source',
			[
				'label'   => __( 'Episode Source', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => $default,
				'options' => [
					'current'  => __( 'Current Episode', 'elementor-podcast-manager' ),
					'specific' => __( 'Specific Episode', 'elementor-podcast-manager' ),
					'latest'   => __( 'Latest Episode', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_control(
			'episode_id',
			[
				'label'     => __( 'Choose Episode', 'elementor-podcast-manager' ),
				'type'      => 'epm_episode_select',
				'default'   => '',
				'condition' => [ 'source' => 'specific' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Resolve the episode for the current widget settings.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $default_source Fallback source.
	 * @return array<string, mixed>|null
	 */
	protected function resolve_widget_episode( array $settings, string $default_source = 'current' ): ?array {
		return epm()->renderer->resolve_episode(
			sanitize_key( $settings['source'] ?? $default_source ),
			(int) ( $settings['episode_id'] ?? 0 )
		);
	}

	/**
	 * Add a show/hide switcher control.
	 *
	 * @param string $id Control ID.
	 * @param string $label Label.
	 * @param bool   $default Default state.
	 * @return void
	 */
	/**
	 * Add a show/hide switcher control.
	 *
	 * @param string               $id      Control ID.
	 * @param string               $label   Label.
	 * @param bool                 $default Default state.
	 * @param array<string, mixed> $extra   Extra control args (description, condition, …).
	 * @return void
	 */
	protected function add_toggle( string $id, string $label, bool $default = true, array $extra = [] ): void {
		$this->add_control(
			$id,
			array_merge(
				[
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'label_on'     => __( 'Show', 'elementor-podcast-manager' ),
					'label_off'    => __( 'Hide', 'elementor-podcast-manager' ),
					'return_value' => 'yes',
					'default'      => $default ? 'yes' : '',
				],
				$extra
			)
		);
	}

	/**
	 * Whether a switcher setting is on.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $key Setting key.
	 * @param bool   $default Default when unset.
	 * @return bool
	 */
	protected function toggle_on( array $settings, string $key, bool $default = true ): bool {
		if ( ! isset( $settings[ $key ] ) ) {
			return $default;
		}

		return 'yes' === $settings[ $key ];
	}

	/**
	 * Add the "Style Source" control at the top of a style section.
	 * 'global' inherits Podcast → Design tokens, 'custom' reveals
	 * per-widget controls that set --epm-* variables on the widget root.
	 *
	 * @return void
	 */
	protected function add_style_source_control(): void {
		$this->add_control(
			'style_source',
			[
				'label'   => __( 'Style Source', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'global',
				'options' => [
					'global' => __( 'Use Global Podcast Styles', 'elementor-podcast-manager' ),
					'custom' => __( 'Custom', 'elementor-podcast-manager' ),
				],
			]
		);
	}

	/**
	 * Condition array for controls that only apply to custom styling.
	 *
	 * @return array<string, string>
	 */
	protected function custom_condition(): array {
		return [ 'style_source' => 'custom' ];
	}

	/**
	 * Add a color control that sets an --epm-* token on a selector.
	 *
	 * @param string $id Control ID.
	 * @param string $label Label.
	 * @param string $token CSS variable name (e.g. --epm-accent).
	 * @param string $selector Selector relative to {{WRAPPER}}.
	 * @return void
	 */
	protected function add_token_color( string $id, string $label, string $token, string $selector ): void {
		$this->add_control(
			$id,
			[
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					$selector => $token . ': {{VALUE}};',
				],
				'condition' => $this->custom_condition(),
			]
		);
	}
}
