<?php
/**
 * Elementor widget: Podcast Metadata (Layer 3).
 *
 * Renders selected metadata fields as a separator-joined line. Values come
 * from \EPM\Renderer::metadata_value(); "company" maps to the guest company
 * field, which the renderer does not expose as a standalone field.
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

final class EpisodeMetadataWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-episode-metadata';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Metadata', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-info';
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
		return [ 'podcast', 'episode', 'metadata', 'meta', 'date', 'duration', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_metadata',
			[
				'label' => __( 'Metadata', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'fields',
			[
				'label'    => __( 'Fields', 'elementor-podcast-manager' ),
				'type'     => Controls_Manager::SELECT2,
				'multiple' => true,
				'default'  => [ 'episode_number', 'date', 'duration', 'guest' ],
				'options'  => [
					'episode_number' => __( 'Episode Number', 'elementor-podcast-manager' ),
					'season'         => __( 'Season', 'elementor-podcast-manager' ),
					'date'           => __( 'Date', 'elementor-podcast-manager' ),
					'duration'       => __( 'Duration', 'elementor-podcast-manager' ),
					'guest'          => __( 'Guest', 'elementor-podcast-manager' ),
					'company'        => __( 'Company', 'elementor-podcast-manager' ),
					'type'           => __( 'Episode Type', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_control(
			'separator',
			[
				'label'   => __( 'Separator', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::TEXT,
				'default' => ' · ',
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_metadata',
			[
				'label' => __( 'Metadata', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'metadata_color', __( 'Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-meta' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'metadata_typography',
				'selector'  => '{{WRAPPER}} .epm-meta',
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

		$fields = $settings['fields'] ?? [];
		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		// Exactly as typed, spaces included (sanitize_text_field() and
		// wp_strip_all_tags() would trim them); tags and line breaks go,
		// and the text is escaped on output.
		$separator = mb_substr( str_replace( [ "\r", "\n", "\t" ], ' ', strip_tags( (string) ( $settings['separator'] ?? ' · ' ) ) ), 0, 20 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- keeps leading and trailing spaces.
		$items     = [];

		foreach ( $fields as $field ) {
			$field = sanitize_key( (string) $field );

			if ( 'company' === $field ) {
				$value = '' !== (string) ( $episode['guest_company'] ?? '' )
					? esc_html( (string) $episode['guest_company'] )
					: '';
			} else {
				$value = epm()->renderer->metadata_value( $episode, $field );
			}

			if ( '' !== $value ) {
				$items[] = '<span class="epm-meta__item epm-meta__item--' . esc_attr( $field ) . '">' . $value . '</span>';
			}
		}

		if ( empty( $items ) ) {
			$this->editor_placeholder( __( 'None of the selected fields has a value for this episode.', 'elementor-podcast-manager' ) );
			return;
		}

		\EPM\Assets::enqueue_style();

		echo '<p class="epm-meta epm-meta--standalone">' . implode( '<span class="epm-meta__sep" aria-hidden="true">' . esc_html( $separator ) . '</span>', $items ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above, separator escaped inline.
	}
}
