<?php
/**
 * Elementor widget: Podcast Player (Layer 3).
 *
 * A configuration UI for the single player engine (\EPM\Renderer::player()).
 * Layouts (minimal/compact/editorial/artwork/full) and every detail map
 * 1:1 onto renderer arguments — no markup is duplicated here. Layout and
 * details default to Podcast → Design ("Default"); see WidgetHelpers.
 *
 * @package EPM
 */

namespace EPM\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PodcastPlayerWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-podcast-player';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Podcast Player', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-play';
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
		return [ 'podcast', 'episode', 'audio', 'player', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->add_episode_source_section();

		$this->start_controls_section(
			'section_player',
			[
				'label' => __( 'Player', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_schema_control();

		$this->add_layout_control(
			__( 'Layout', 'elementor-podcast-manager' ),
			self::layouts(),
			'default_player_layout'
		);

		$this->add_details_defaults_control();
		foreach ( self::detail_controls() as $id => [ $label, $description ] ) {
			$this->add_detail_control( $id, $label, 'player', '' !== $description ? [ 'description' => $description ] : [] );
		}

		$this->add_toggle( 'sticky', __( 'Enable Sticky Player', 'elementor-podcast-manager' ), false, [ 'separator' => 'before' ] );

		$this->end_controls_section();

		$this->register_style_controls();
		$this->add_task_guidance();
	}

	/**
	 * Player layouts.
	 *
	 * @return array<string, string>
	 */
	public static function layouts(): array {
		return [
			'minimal'   => __( 'Minimal', 'elementor-podcast-manager' ),
			'compact'   => __( 'Compact', 'elementor-podcast-manager' ),
			'editorial' => __( 'Editorial', 'elementor-podcast-manager' ),
			'artwork'   => __( 'Artwork', 'elementor-podcast-manager' ),
			'full'      => __( 'Full', 'elementor-podcast-manager' ),
		];
	}

	/**
	 * Detail controls of a player: detail => [ label, editor note ].
	 * Shared with the Latest Episode widget.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function detail_controls(): array {
		$hidden_compact = __( 'Not shown in the Minimal and Compact layouts.', 'elementor-podcast-manager' );

		return [
			'show_artwork'        => [ __( 'Artwork', 'elementor-podcast-manager' ), __( 'Not shown in the Minimal and Editorial layouts.', 'elementor-podcast-manager' ) ],
			'show_episode_label'  => [ __( 'Episode Label', 'elementor-podcast-manager' ), __( 'A line above the title with the episode number and season (when shown below) and "Bonus" or "Trailer". Empty for a regular episode without number and season.', 'elementor-podcast-manager' ) ],
			'show_episode_number' => [ __( 'Episode Number in Label', 'elementor-podcast-manager' ), '' ],
			'show_season'         => [ __( 'Season in Label', 'elementor-podcast-manager' ), '' ],
			'show_title'          => [ __( 'Title', 'elementor-podcast-manager' ), '' ],
			'show_guest'          => [ __( 'Guest', 'elementor-podcast-manager' ), '' ],
			'show_description'    => [ __( 'Description', 'elementor-podcast-manager' ), $hidden_compact ],
			'show_date'           => [ __( 'Date', 'elementor-podcast-manager' ), '' ],
			'show_duration'       => [ __( 'Duration', 'elementor-podcast-manager' ), '' ],
			'show_playback_speed' => [ __( 'Playback Speed', 'elementor-podcast-manager' ), $hidden_compact ],
			'show_skip_backward'  => [ __( 'Skip Backward (−15s)', 'elementor-podcast-manager' ), '' ],
			'show_skip_forward'   => [ __( 'Skip Forward (+30s)', 'elementor-podcast-manager' ), '' ],
			'show_volume'         => [ __( 'Volume', 'elementor-podcast-manager' ), $hidden_compact ],
			'show_download'       => [ __( 'Download Button', 'elementor-podcast-manager' ), $hidden_compact ],
			'show_share'          => [ __( 'Share Menu', 'elementor-podcast-manager' ), __( 'Copy link, link at the current position and embed code. Not shown in the Minimal and Compact layouts.', 'elementor-podcast-manager' ) ],
			'show_chapters_link'  => [ __( 'Chapters Toggle', 'elementor-podcast-manager' ), '' ],
			'show_platform_links' => [ __( 'Platform Links', 'elementor-podcast-manager' ), '' ],
		];
	}

	/**
	 * Raw data for the editor and for saving: a widget saved by 1.3.0
	 * gets its details made explicit (it renders the same).
	 *
	 * @param bool $with_html_content With the rendered HTML.
	 * @return array
	 */
	public function get_raw_data( $with_html_content = false ) {
		$data             = parent::get_raw_data( $with_html_content );
		$data['settings'] = $this->explicit_details( (array) ( $data['settings'] ?? [] ), 'player' );

		return $data;
	}

	/**
	 * Register all style sections (token-based, custom overrides only).
	 *
	 * @return void
	 */
	private function register_style_controls(): void {
		// Container.
		$this->start_controls_section(
			'section_style_container',
			[
				'label' => __( 'Container', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		$this->add_token_color( 'container_background', __( 'Background', 'elementor-podcast-manager' ), '--epm-background', '{{WRAPPER}} .epm-player' );
		$this->add_token_color( 'container_border_color', __( 'Border Color', 'elementor-podcast-manager' ), '--epm-border', '{{WRAPPER}} .epm-player' );

		$this->add_responsive_control(
			'container_radius',
			[
				'label'      => __( 'Border Radius', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-player' => '--epm-radius: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => 'container_border',
				'selector'  => '{{WRAPPER}} .epm-player',
				// The group's own color field would be "container_border_color",
				// which is the Border Color token control above: excluded to
				// avoid a duplicate control ID (the token color applies).
				'exclude'   => [ 'color' ],
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'container_padding',
			[
				'label'      => __( 'Padding', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .epm-player' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'container_gap',
			[
				'label'      => __( 'Gap', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-player' => '--epm-gap: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'      => 'container_shadow',
				'selector'  => '{{WRAPPER}} .epm-player',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();

		// Artwork.
		$this->start_controls_section(
			'section_style_artwork',
			[
				'label'     => __( 'Artwork', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);


		$this->add_responsive_control(
			'artwork_size',
			[
				'label'      => __( 'Size', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 32, 'max' => 400 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-player' => '--epm-artwork-size: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'artwork_radius',
			[
				'label'      => __( 'Border Radius', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-player' => '--epm-artwork-radius: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->end_controls_section();

		// Label.
		$this->start_controls_section(
			'section_style_label',
			[
				'label'     => __( 'Label', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_token_color( 'label_color', __( 'Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-player__label' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'label_typography',
				'selector'  => '{{WRAPPER}} .epm-player__label',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();

		// Title.
		$this->start_controls_section(
			'section_style_title',
			[
				'label'     => __( 'Title', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_token_color( 'title_color', __( 'Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-player__title' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'title_typography',
				'selector'  => '{{WRAPPER}} .epm-player__title',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();

		// Metadata.
		$this->start_controls_section(
			'section_style_meta',
			[
				'label'     => __( 'Metadata', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_token_color( 'meta_color', __( 'Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-player .epm-meta' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'meta_typography',
				'selector'  => '{{WRAPPER}} .epm-player .epm-meta',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();

		// Play button.
		$this->start_controls_section(
			'section_style_play',
			[
				'label'     => __( 'Play Button', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);


		$this->add_responsive_control(
			'play_button_size',
			[
				'label'      => __( 'Size', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 32, 'max' => 120 ] ],
				'selectors'  => [
					'{{WRAPPER}} .epm-player__play' => '--epm-play-size: {{SIZE}}{{UNIT}};',
				],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'play_button_radius',
			[
				'label'      => __( 'Border Radius', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-player__play' => '--epm-play-radius: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->start_controls_tabs( 'play_button_style_tabs' );

		$this->start_controls_tab(
			'play_button_tab_normal',
			[
				'label'     => __( 'Normal', 'elementor-podcast-manager' ),
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_control(
			'play_button_background',
			[
				'label'     => __( 'Background', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .epm-player__play' => '--epm-play-background: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'play_button_color',
			[
				'label'     => __( 'Icon Color', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .epm-player__play' => '--epm-play-color: {{VALUE}};' ],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'play_button_border',
				// :not(#epm) matches the theme-proof button rule's specificity.
				'selector' => '{{WRAPPER}} .epm-player__play:not(#epm)',
			]
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'play_button_tab_hover',
			[
				'label'     => __( 'Hover', 'elementor-podcast-manager' ),
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_control(
			'play_button_background_hover',
			[
				'label'     => __( 'Background', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .epm-player__play' => '--epm-play-background-hover: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'play_button_color_hover',
			[
				'label'     => __( 'Icon Color', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .epm-player__play' => '--epm-play-color-hover: {{VALUE}};' ],
			]
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// Timeline.
		$this->start_controls_section(
			'section_style_timeline',
			[
				'label'     => __( 'Timeline', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);


		$this->add_control(
			'timeline_track_color',
			[
				'label'     => __( 'Track Color', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				// Sets the track token, so the speed pill outline follows too.
				'selectors' => [ '{{WRAPPER}} .epm-player' => '--epm-track: {{VALUE}};' ],
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_control(
			'timeline_played_color',
			[
				'label'     => __( 'Played Color', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .epm-player__progress' => 'background: {{VALUE}};' ],
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'timeline_height',
			[
				'label'      => __( 'Height', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 2, 'max' => 16 ] ],
				'selectors'  => [ '{{WRAPPER}}' => '--epm-progress-height: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->end_controls_section();

		// Time + secondary controls.
		$this->start_controls_section(
			'section_style_controls',
			[
				'label'     => __( 'Time & Secondary Controls', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_token_color( 'time_color', __( 'Time Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-player__times' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'time_typography',
				'selector'  => '{{WRAPPER}} .epm-player__times',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'secondary_icon_size',
			[
				'label'      => __( 'Secondary Icon Size', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 12, 'max' => 40 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-player' => '--epm-secondary-icon-size: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_button_shape_control( 'secondary_button_shape', '{{WRAPPER}} .epm-player' );

		$this->add_control(
			'secondary_color',
			[
				'label'     => __( 'Secondary Color', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .epm-player__secondary' => '--epm-secondary-color: {{VALUE}};' ],
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_control(
			'secondary_color_hover',
			[
				'label'     => __( 'Secondary Hover Color', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .epm-player' => '--epm-secondary-hover: {{VALUE}};',
				],
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

		\EPM\Assets::enqueue();

		$args           = \EPM\Details::resolve( 'player', $this->detail_values( 'player' ) );
		$args['layout'] = sanitize_key( $settings['layout'] ?? '' );
		$args['sticky'] = $this->toggle_on( $settings, 'sticky', false );

		echo epm()->renderer->player( $episode, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
	}
}
