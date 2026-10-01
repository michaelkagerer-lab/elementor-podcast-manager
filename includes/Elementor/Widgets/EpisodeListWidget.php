<?php
/**
 * Elementor widget: Episode List (Layer 3).
 *
 * Configurable query (number, order, season, topic, pagination) rendered through
 * \EPM\Renderer::episode_list() — the same row/card primitives used
 * everywhere else. Numbered pagination uses WP_Query + paginate_links().
 *
 * @package EPM
 */

namespace EPM\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EpisodeListWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Paginated lists rendered on this request (see render()).
	 *
	 * @var int
	 */
	private static int $paginated = 0;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-episode-list';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Episode List', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-bullet-list';
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
		return [ 'podcast', 'episode', 'list', 'archive', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_query',
			[
				'label' => __( 'Query', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'number',
			[
				'label'   => __( 'Number of Episodes', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
			]
		);

		$this->add_control(
			'order',
			[
				'label'   => __( 'Order', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => [
					'DESC' => __( 'Descending', 'elementor-podcast-manager' ),
					'ASC'  => __( 'Ascending', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_control(
			'orderby',
			[
				'label'   => __( 'Order By', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => [
					'date'           => __( 'Publish Date', 'elementor-podcast-manager' ),
					'episode_number' => __( 'Episode Number', 'elementor-podcast-manager' ),
					'title'          => __( 'Title', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->add_control(
			'season',
			[
				'label'       => __( 'Season', 'elementor-podcast-manager' ),
				'description' => __( '0 shows episodes from all seasons.', 'elementor-podcast-manager' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
			]
		);

		// Topics (taxonomy registered with the episode post type).
		if ( taxonomy_exists( \EPM\Renderer::TOPIC_TAXONOMY ) ) {
			$this->add_control(
				'topics',
				[
					'label'       => __( 'Topic', 'elementor-podcast-manager' ),
					'description' => __( 'Shows episodes with any of the chosen topics. Leave empty for all topics.', 'elementor-podcast-manager' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'label_block' => true,
					'default'     => [],
					'options'     => $this->topic_options(),
				]
			);
		}

		$this->add_control(
			'pagination',
			[
				'label'   => __( 'Pagination', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'none',
				'options' => [
					'none'     => __( 'None', 'elementor-podcast-manager' ),
					'numbered' => __( 'Numbered', 'elementor-podcast-manager' ),
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_list',
			[
				'label' => __( 'List', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_schema_control();

		$layouts = [
			'list'           => __( 'List', 'elementor-podcast-manager' ),
			'editorial-rows' => __( 'Editorial Rows', 'elementor-podcast-manager' ),
			'cards'          => __( 'Cards', 'elementor-podcast-manager' ),
			'grid'           => __( 'Grid', 'elementor-podcast-manager' ),
			'minimal'        => __( 'Minimal List', 'elementor-podcast-manager' ),
		];
		$this->add_layout_control( __( 'Layout', 'elementor-podcast-manager' ), $layouts, 'default_episode_layout' );

		// Artwork only exists on cards: offer it for card layouts, and for
		// Default while Podcast → Design uses a card layout.
		$card_layouts = [ 'cards', 'grid' ];
		if ( in_array( (string) epm()->design->get( 'default_episode_layout' ), $card_layouts, true ) ) {
			$card_layouts[] = '';
		}

		$this->add_details_defaults_control();
		$this->add_detail_control( 'show_artwork', __( 'Artwork (cards/grid only)', 'elementor-podcast-manager' ), 'list', [ 'condition' => [ 'layout' => $card_layouts ] ] );
		$this->add_detail_control( 'show_episode_number', __( 'Episode Number', 'elementor-podcast-manager' ), 'list', [ 'description' => __( 'Not shown in the Minimal List layout.', 'elementor-podcast-manager' ) ] );
		$this->add_detail_control( 'show_title', __( 'Title', 'elementor-podcast-manager' ), 'list' );
		$this->add_detail_control( 'show_guest', __( 'Guest', 'elementor-podcast-manager' ), 'list' );
		$this->add_detail_control( 'show_excerpt', __( 'Excerpt', 'elementor-podcast-manager' ), 'list' );
		$this->add_detail_control( 'show_date', __( 'Date', 'elementor-podcast-manager' ), 'list' );
		$this->add_detail_control( 'show_duration', __( 'Duration', 'elementor-podcast-manager' ), 'list' );
		$this->add_detail_control( 'show_play_button', __( 'Play Button', 'elementor-podcast-manager' ), 'list' );
		if ( taxonomy_exists( \EPM\Renderer::TOPIC_TAXONOMY ) ) {
			$this->add_detail_control( 'show_topics', __( 'Topics', 'elementor-podcast-manager' ), 'list' );
		}

		$this->add_control(
			'excerpt_length',
			[
				'label'   => __( 'Excerpt Length (words)', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 20,
				'min'     => 0,
				'max'     => 200,
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_list',
			[
				'label' => __( 'List', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		// A background also brings inner padding, so rows and cards do not
		// sit on the colored edge (Elementor prints it only when a color is set).
		$this->add_control(
			'list_background',
			[
				'label'     => __( 'Background', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .epm-episode-list' => '--epm-list-background: {{VALUE}}; --epm-list-padding: var(--epm-gap, 24px);',
				],
				'condition' => $this->custom_condition(),
			]
		);
		$this->add_token_color( 'list_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-episode-list' );
		$this->add_token_color( 'list_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-episode-list' );
		$this->add_token_color( 'list_accent', __( 'Hover and Playing Color', 'elementor-podcast-manager' ), '--epm-accent', '{{WRAPPER}} .epm-episode-list' );
		$this->add_token_color( 'list_border', __( 'Border Color', 'elementor-podcast-manager' ), '--epm-border', '{{WRAPPER}} .epm-episode-list' );

		$this->add_responsive_control(
			'list_radius',
			[
				'label'      => __( 'Border Radius', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-episode-list' => '--epm-radius: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'list_gap',
			[
				'label'      => __( 'Gap', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 100 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-episode-list' => '--epm-gap: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_responsive_control(
			'list_artwork_radius',
			[
				'label'      => __( 'Card Artwork Radius', 'elementor-podcast-manager' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'selectors'  => [ '{{WRAPPER}} .epm-episode-list' => '--epm-card-artwork-radius: {{SIZE}}{{UNIT}};' ],
				'condition'  => $this->custom_condition(),
			]
		);

		$this->add_button_shape_control( 'list_button_shape', '{{WRAPPER}} .epm-episode-list' );

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_typography',
			[
				'label'     => __( 'Typography', 'elementor-podcast-manager' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $this->custom_condition(),
			]
		);


		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'list_title_typography',
				'label'     => __( 'Title', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-episode-card__title, {{WRAPPER}} .epm-episode-row__title',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'list_meta_typography',
				'label'     => __( 'Metadata', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-episode-list .epm-meta',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => 'list_item_border',
				'label'     => __( 'Item Border', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-episode-card, {{WRAPPER}} .epm-episode-row',
				'condition' => $this->custom_condition(),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Whether this is the first Episode List with numbered pagination in
	 * its Elementor document (in document order, so rendering twice, as
	 * some SEO plugins do, cannot change it). Without a document: the
	 * first one rendered on this request.
	 *
	 * @return bool
	 */
	private function is_first_paginated_list(): bool {
		$document = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ? \Elementor\Plugin::$instance->documents->get_current() : null;
		$first    = $document ? self::first_paginated_list( (array) $document->get_elements_data() ) : null;

		if ( null === $first ) {
			return 0 === self::$paginated++;
		}

		return (string) $this->get_id() === $first;
	}

	/**
	 * ID of the first paginated Episode List in element data.
	 *
	 * @param array $elements Elements data.
	 * @return string|null Null when the data holds none (or not this widget).
	 */
	private static function first_paginated_list( array $elements ): ?string {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'epm-episode-list' === ( $element['widgetType'] ?? '' ) && 'numbered' === ( $element['settings']['pagination'] ?? '' ) ) {
				return (string) ( $element['id'] ?? '' );
			}
			$found = self::first_paginated_list( (array) ( $element['elements'] ?? [] ) );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
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
		$data['settings'] = $this->explicit_details( (array) ( $data['settings'] ?? [] ), 'list' );

		return $data;
	}

	/**
	 * Topic choices for the Topic control (slug => name).
	 *
	 * @return array<string, string>
	 */
	private function topic_options(): array {
		$terms = get_terms(
			[
				'taxonomy'   => \EPM\Renderer::TOPIC_TAXONOMY,
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'name',
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$options = [];
		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		return $options;
	}

	/**
	 * Render the widget.
	 *
	 * @return void
	 */
	protected function render(): void {
		\EPM\Assets::enqueue();

		$settings = $this->get_settings_for_display();

		$query_args = [
			'posts_per_page' => max( 1, min( 100, (int) ( $settings['number'] ?? 10 ) ) ),
			'orderby'        => sanitize_key( $settings['orderby'] ?? 'date' ),
			'order'          => 'ASC' === strtoupper( (string) ( $settings['order'] ?? '' ) ) ? 'ASC' : 'DESC',
		];

		$season = (int) ( $settings['season'] ?? 0 );
		if ( $season > 0 ) {
			$query_args['season'] = $season;
		}

		$topic_args = \EPM\Renderer::topic_query_args( \EPM\Renderer::topic_slugs( $settings['topics'] ?? [] ) );
		$query_args = array_merge( $query_args, $topic_args );

		$args                   = \EPM\Details::resolve( 'list', $this->detail_values( 'list' ) );
		$args['layout']         = sanitize_key( $settings['layout'] ?? '' );
		$args['excerpt_length'] = max( 0, (int) ( $settings['excerpt_length'] ?? 20 ) );
		// Empty season/topic → "No episodes in this selection" + link to all.
		$args['filtered'] = $season > 0 || ! empty( $topic_args );

		if ( 'numbered' === ( $settings['pagination'] ?? 'none' ) ) {
			// The first paginated list of a page pages with /page/N/ (static
			// front pages with "page", archives with "paged"); every further
			// list has its own ?epm-page-<id>=N, so lists page on their own.
			$own   = ! $this->is_first_paginated_list();
			$param = 'epm-page-' . sanitize_key( (string) $this->get_id() );
			if ( $own ) {
				$paged = isset( $_GET[ $param ] ) ? max( 1, absint( wp_unslash( $_GET[ $param ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page number.
			} else {
				$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
			}
			$query_args['paged'] = $paged;
			$query               = epm()->episodes->query( $query_args );

			echo epm()->renderer->episode_list( $query->posts, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.

			if ( $query->max_num_pages > 1 ) {
				$pagination = [
					'total'   => (int) $query->max_num_pages,
					'current' => $paged,
					'type'    => 'list',
				];
				if ( $own ) {
					$pagination['base']   = esc_url_raw( add_query_arg( $param, '%#%', remove_query_arg( $param ) ) );
					$pagination['format'] = '';
				}
				$links = paginate_links( $pagination );

				if ( $links ) {
					echo '<nav class="epm-pagination" aria-label="' . esc_attr__( 'Episode pages', 'elementor-podcast-manager' ) . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}

			return;
		}

		$posts = epm()->episodes->get_episodes( $query_args );

		echo epm()->renderer->episode_list( $posts, $args );
	}
}
