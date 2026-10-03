<?php
/**
 * Elementor widget: Latest Episode (Layer 3).
 *
 * Source is fixed to "latest". Reuses the single player engine for the
 * player part; a lightweight header card is composed from renderer
 * primitives when the player is hidden. Layout and details default to
 * Podcast → Design ("Default"); see WidgetHelpers.
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

final class LatestEpisodeWidget extends Widget_Base {

	use WidgetHelpers;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'epm-latest-episode';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Latest Episode', 'elementor-podcast-manager' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-star';
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
		return [ 'podcast', 'episode', 'latest', 'newest', 'player', 'epm' ];
	}

	/**
	 * Register content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_latest',
			[
				'label' => __( 'Latest Episode', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_schema_control();

		$this->add_toggle( 'show_player', __( 'Audio Player', 'elementor-podcast-manager' ), true, [ 'description' => __( 'Off shows a card with the artwork, title, details and description instead of the player.', 'elementor-podcast-manager' ) ] );

		$this->add_layout_control(
			__( 'Player Layout', 'elementor-podcast-manager' ),
			PodcastPlayerWidget::layouts(),
			'default_player_layout',
			[ 'condition' => [ 'show_player' => 'yes' ] ]
		);

		$this->add_details_defaults_control();
		// The card (player off) shows artwork, title, guest, date, duration
		// and description; everything else belongs to the player.
		$card = [ 'show_artwork', 'show_title', 'show_guest', 'show_description', 'show_date', 'show_duration' ];
		foreach ( PodcastPlayerWidget::detail_controls() as $id => [ $label, $description ] ) {
			$extra = '' !== $description ? [ 'description' => $description ] : [];
			if ( 'show_artwork' === $id ) {
				$extra['description'] = __( 'Not shown in the Minimal and Editorial player layouts.', 'elementor-podcast-manager' );
			} elseif ( 'show_description' === $id ) {
				$extra['description'] = __( 'Not shown in the Minimal and Compact player layouts.', 'elementor-podcast-manager' );
			}
			if ( ! in_array( $id, $card, true ) ) {
				$extra['condition'] = [ 'show_player' => 'yes' ];
			}
			$this->add_detail_control( $id, $label, 'latest', $extra );
		}

		$this->add_toggle(
			'sticky',
			__( 'Enable Sticky Player', 'elementor-podcast-manager' ),
			false,
			[
				'separator'   => 'before',
				'description' => __( 'Playback started here keeps its controls in a bar at the bottom of the screen.', 'elementor-podcast-manager' ),
				'condition'   => [ 'show_player' => 'yes' ],
			]
		);

		$this->add_toggle( 'show_cta', __( 'Call to Action', 'elementor-podcast-manager' ), false, [ 'description' => __( 'A button below the episode. Shown once it has a text and a link.', 'elementor-podcast-manager' ) ] );

		$this->add_control(
			'cta_text',
			[
				'label'     => __( 'CTA Text', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Listen now', 'elementor-podcast-manager' ),
				'condition' => [ 'show_cta' => 'yes' ],
			]
		);

		$this->add_control(
			'cta_url',
			[
				'label'       => __( 'CTA URL', 'elementor-podcast-manager' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => __( 'https://…', 'elementor-podcast-manager' ),
				'condition'   => [ 'show_cta' => 'yes' ],
			]
		);

		$this->end_controls_section();

		// Style.
		$this->start_controls_section(
			'section_style_latest',
			[
				'label' => __( 'Latest Episode', 'elementor-podcast-manager' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_style_source_control();
		// A background also brings inner padding, so the content does not
		// sit on the colored edge (Elementor prints it only when a color
		// is set).
		$this->add_control(
			'latest_background',
			[
				'label'     => __( 'Background', 'elementor-podcast-manager' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .epm-latest' => '--epm-latest-background: {{VALUE}}; --epm-latest-padding: var(--epm-gap, 24px);',
				],
				'condition' => $this->custom_condition(),
			]
		);
		$this->add_token_color( 'latest_text', __( 'Text Color', 'elementor-podcast-manager' ), '--epm-text', '{{WRAPPER}} .epm-latest' );
		$this->add_token_color( 'latest_muted', __( 'Muted Text Color', 'elementor-podcast-manager' ), '--epm-text-muted', '{{WRAPPER}} .epm-latest' );
		$this->add_token_color( 'latest_accent', __( 'Accent Color', 'elementor-podcast-manager' ), '--epm-accent', '{{WRAPPER}} .epm-latest' );
		$this->add_button_shape_control( 'latest_button_shape', '{{WRAPPER}} .epm-latest' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'latest_title_typography',
				'label'     => __( 'Title', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-latest__title, {{WRAPPER}} .epm-latest .epm-player__title',
				'condition' => $this->custom_condition(),
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'latest_meta_typography',
				'label'     => __( 'Metadata', 'elementor-podcast-manager' ),
				'selector'  => '{{WRAPPER}} .epm-latest .epm-meta',
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
		$episode  = epm()->renderer->resolve_episode( 'latest' );

		if ( ! $episode ) {
			$this->editor_placeholder( __( 'No published episode with audio yet.', 'elementor-podcast-manager' ) );
			return;
		}

		$renderer = epm()->renderer;
		$details  = \EPM\Details::resolve( 'latest', $this->detail_values( 'latest' ) );
		$player   = $this->toggle_on( $settings, 'show_player', true );

		if ( $player ) {
			\EPM\Assets::enqueue();
		} else {
			\EPM\Assets::enqueue_style();
		}

		echo '<div class="epm-latest">';

		if ( $player ) {
			$args           = $details;
			$args['layout'] = sanitize_key( $settings['layout'] ?? '' );
			$args['sticky'] = $this->toggle_on( $settings, 'sticky', false );
			echo $renderer->player( $episode, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
		} else {
			// Header card without player, composed from renderer primitives.
			echo '<div class="epm-latest__header">';

			if ( $details['show_artwork'] ) {
				$art = $renderer->artwork( $episode, 'medium', 'epm-latest__artwork-img' );
				if ( '' !== $art ) {
					echo '<div class="epm-latest__artwork">' . $art . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}

			echo '<div class="epm-latest__info">';

			if ( $details['show_title'] ) {
				echo '<h3 class="epm-latest__title">' . esc_html( (string) $episode['title'] ) . '</h3>';
			}

			$meta_fields = [];
			if ( $details['show_guest'] ) {
				$meta_fields[] = 'guest';
			}
			if ( $details['show_date'] ) {
				$meta_fields[] = 'date';
			}
			if ( $details['show_duration'] ) {
				$meta_fields[] = 'duration';
			}
			if ( ! empty( $meta_fields ) ) {
				echo $renderer->metadata( $episode, $meta_fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			if ( $details['show_description'] ) {
				$desc = '' !== (string) ( $episode['short_description'] ?? '' )
					? (string) $episode['short_description']
					: wp_trim_words( wp_strip_all_tags( (string) ( $episode['description'] ?? '' ) ), 40 );
				if ( '' !== $desc ) {
					echo '<p class="epm-latest__description">' . esc_html( $desc ) . '</p>';
				}
			}

			echo '</div></div>';
		}

		if ( $this->toggle_on( $settings, 'show_cta', false ) ) {
			$cta_url = $settings['cta_url'] ?? [];
			$url     = is_array( $cta_url ) ? (string) ( $cta_url['url'] ?? '' ) : '';
			$text    = sanitize_text_field( $settings['cta_text'] ?? '' );

			if ( '' !== $url && '' !== $text ) {
				$target = ! empty( $cta_url['is_external'] ) ? ' target="_blank"' : '';
				$rel    = ! empty( $cta_url['nofollow'] ) ? ' rel="nofollow"' : '';

				echo '<div class="epm-latest__cta-wrap"><a class="epm-latest__cta" href="' . esc_url( $url ) . '"' . $target . $rel . '>' . esc_html( $text ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- target/rel are hardcoded attribute strings.
			} else {
				$this->editor_placeholder( __( 'The call to action shows once it has a text and a link (CTA URL).', 'elementor-podcast-manager' ) );
			}
		}

		echo '</div>';
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
		$data['settings'] = $this->explicit_details( (array) ( $data['settings'] ?? [] ), 'latest' );

		return $data;
	}
}
