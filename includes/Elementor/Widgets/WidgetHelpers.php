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
use EPM\Details;

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
				'description' => __( 'Current Episode follows the episode page or loop item. Use Latest Episode on your homepage, or Specific Episode for a fixed selection.', 'elementor-podcast-manager' ),
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
		$source = sanitize_key( $settings['source'] ?? $default_source );
		$episode = epm()->renderer->resolve_episode( $source, (int) ( $settings['episode_id'] ?? 0 ) );
		$loop = get_post();
		$queried = get_queried_object();
		if ( $episode && 'current' === $source && $this->is_editor()
			&& ! ( $loop instanceof \WP_Post && \EPM\EpisodePostType::CPT === $loop->post_type )
			&& ! ( $queried instanceof \WP_Post && \EPM\EpisodePostType::CPT === $queried->post_type ) ) {
			\EPM\Assets::enqueue_style();
			echo '<p class="epm-editor-placeholder" data-epm-preview-context>' . esc_html__( 'Preview episode only. Current Episode appears on episode pages. For this page, choose Latest Episode or Specific Episode in the Episode settings.', 'elementor-podcast-manager' ) . '</p>';
		}
		return $episode;
	}

	/**
	 * Podcast widgets show live data (new episodes, "latest episode",
	 * pagination), so Elementor's element cache must never store their
	 * output. Harmless on Elementor versions without element caching.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Whether the widget is rendering inside the Elementor editor.
	 *
	 * @return bool
	 */
	protected function is_editor(): bool {
		return class_exists( '\\Elementor\\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * Explain an empty widget inside the editor (never on the live site),
	 * so designers can see and select widgets that currently render nothing.
	 *
	 * @param string $message Explanation.
	 * @return void
	 */
	protected function editor_placeholder( string $message ): void {
		if ( ! $this->is_editor() ) {
			return;
		}

		\EPM\Assets::enqueue_style();

		$action = '';
		if ( __( 'The call to action shows once it has a text and a link (CTA URL).', 'elementor-podcast-manager' ) !== $message ) {
			$url = admin_url( 'edit.php?post_type=' . \EPM\EpisodePostType::CPT );
			$label = __( 'Manage episodes (opens in a new tab)', 'elementor-podcast-manager' );
			if ( 'epm-subscribe-links' === $this->get_name() ) {
				$url = \EPM\Capabilities::can_manage_podcast() ? admin_url( 'admin.php?page=epm-settings' ) : '';
				$label = __( 'Podcast settings', 'elementor-podcast-manager' ) . ' ' . __( '(opens in a new tab)', 'elementor-podcast-manager' );
			}
			if ( $url ) {
				$action = '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></p>';
			}
		}
		echo '<div class="epm-editor-placeholder">' . esc_html( $message ) . $action . '</div>';
	}

	/**
	 * Placeholder message when no episode could be resolved.
	 *
	 * @param array $settings Widget settings.
	 * @return string
	 */
	protected function no_episode_message( array $settings ): string {
		$source = (string) ( $settings['source'] ?? 'current' );

		if ( 'specific' === $source ) {
			return __( 'Choose an episode in the widget settings.', 'elementor-podcast-manager' );
		}

		if ( 'latest' === $source ) {
			return __( 'No published episode with audio yet.', 'elementor-podcast-manager' );
		}

		return __( 'Shows the current episode on episode pages. Publish an episode to preview it here.', 'elementor-podcast-manager' );
	}

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
					'label_on'     => 'sticky' === $id ? __( 'On', 'elementor-podcast-manager' ) : __( 'Show', 'elementor-podcast-manager' ),
					'label_off'    => 'sticky' === $id ? __( 'Off', 'elementor-podcast-manager' ) : __( 'Hide', 'elementor-podcast-manager' ),
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
	 * Schema marker. Widgets inserted or saved since the details defaults
	 * exist carry epm_schema = '2' (always stored: save_default). Widgets
	 * saved by 1.3.0 have no marker: their details keep the values 1.3.0
	 * showed (see detail_values()), and the editor opens them with those
	 * values made explicit (see with_explicit_details()).
	 *
	 * @return void
	 */
	protected function add_schema_control(): void {
		$this->add_control(
			'epm_schema',
			[
				'type'         => Controls_Manager::HIDDEN,
				'default'      => '2',
				'save_default' => true,
			]
		);
	}

	/**
	 * Layout select whose default is "Default (Podcast → Design: …)" ('').
	 * Every other choice differs from the control default, so Elementor
	 * stores it, also when it equals the current Design value: a later
	 * preset or Design change never moves it.
	 *
	 * @param string                $label   Control label.
	 * @param array<string, string> $layouts Layout => label.
	 * @param string                $token   Design token with the default layout.
	 * @param array<string, mixed>  $extra   Extra control args.
	 * @return void
	 */
	protected function add_layout_control( string $label, array $layouts, string $token, array $extra = [] ): void {
		$current = (string) epm()->design->get( $token );

		$this->add_control(
			'layout',
			array_merge(
				[
					'label'   => $label,
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => [
						/* translators: %s: layout name, e.g. Minimal */
						'' => sprintf( __( 'Default (Podcast → Design: %s)', 'elementor-podcast-manager' ), $layouts[ $current ] ?? $current ),
					] + $layouts,
				],
				$extra
			)
		);
	}

	/**
	 * Add a detail as Default / Show / Hide. '' (Default) follows Podcast →
	 * Design → Details shown by default; Show and Hide are stored as 'yes'
	 * and 'no' and win. The Default option names what the site shows now.
	 *
	 * @param string               $id      Detail key, e.g. show_volume.
	 * @param string               $label   Label.
	 * @param string               $context Details context.
	 * @param array<string, mixed> $extra   Extra control args (description, condition, …).
	 * @return void
	 */
	protected function add_detail_control( string $id, string $label, string $context, array $extra = [] ): void {
		$shown = Details::effective( $context )[ $id ] ?? false;
		if ( in_array( $context, [ 'player', 'latest' ], true ) ) {
			$unsupported = [];
			if ( in_array( $id, [ 'show_description', 'show_playback_speed', 'show_volume', 'show_download', 'show_share' ], true ) ) {
				$unsupported = [ 'minimal', 'compact' ];
			} elseif ( 'show_artwork' === $id ) {
				$unsupported = [ 'minimal', 'editorial' ];
			}
			if ( $unsupported ) {
				$terms = [ [ 'name' => 'layout', 'operator' => '!in', 'value' => $unsupported ] ];
				if ( in_array( epm()->design->get( 'default_player_layout' ), $unsupported, true ) ) {
					$terms[] = [ 'name' => 'layout', 'operator' => '!==', 'value' => '' ];
				}
				if ( 'latest' === $context ) {
					if ( isset( $extra['condition']['show_player'] ) ) {
						$terms[] = [ 'name' => 'show_player', 'operator' => '===', 'value' => 'yes' ];
						unset( $extra['condition'] );
						$extra['conditions'] = [ 'terms' => $terms ];
					} else {
						$extra['conditions'] = [ 'relation' => 'or', 'terms' => [ [ 'name' => 'show_player', 'operator' => '!==', 'value' => 'yes' ], [ 'terms' => $terms ] ] ];
					}
				} else {
					$extra['conditions'] = [ 'terms' => $terms ];
				}
			}
		}


		$this->add_control(
			$id,
			array_merge(
				[
					'label'   => $label,
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => [
						'' => $shown
							? __( 'Podcast design (shown)', 'elementor-podcast-manager' )
							: __( 'Podcast design (hidden)', 'elementor-podcast-manager' ),
						'yes' => __( 'Show', 'elementor-podcast-manager' ),
						'no'  => __( 'Hide', 'elementor-podcast-manager' ),
					],
				],
				$extra
			)
		);
	}

	/**
	 * One action that puts every detail of this widget back on Default
	 * (admin/js/epm-elementor-editor.js handles the event, undoable).
	 *
	 * @return void
	 */
	protected function add_details_defaults_control(): void {
		$this->add_control(
			'epm_details_defaults',
			[
				'label'       => __( 'Details', 'elementor-podcast-manager' ),
				'type'        => Controls_Manager::BUTTON,
				'text'        => __( 'Use Podcast → Design defaults', 'elementor-podcast-manager' ),
				'event'       => 'epm:details:defaults',
				'separator'   => 'before',
				'description' => __( 'Each detail below shows, hides or follows Podcast → Design → Details shown by default ("Default"). This button sets every detail of this widget to Default.', 'elementor-podcast-manager' ),
			]
		);
	}

	/**
	 * Whether the widget was saved before the details defaults existed.
	 *
	 * @param array<string, mixed> $raw Stored settings.
	 * @return bool
	 */
	protected function is_legacy_settings( array $raw ): bool {
		return ! isset( $raw['epm_schema'] ) || '' === (string) $raw['epm_schema'];
	}

	/**
	 * The details this widget chose itself (null: Default, inherit).
	 *
	 * Widgets saved by 1.3.0 have no schema marker. Their stored switchers
	 * stay explicit ('yes' shows, '' hides) and a missing one keeps what
	 * 1.3.0 showed for it (Elementor did not store values equal to the
	 * control default). So a later change of the site's details never
	 * changes such a widget until its details are set to Default.
	 *
	 * @param string $context Details context.
	 * @return array<string, bool|null>
	 */
	protected function detail_values( string $context ): array {
		$raw    = (array) $this->get_data( 'settings' );
		$legacy = $this->is_legacy_settings( $raw );
		$frozen = Details::neutral( $context );
		$out    = [];

		foreach ( Details::flags( $context ) as $flag ) {
			$value = $raw[ $flag ] ?? null;
			if ( $legacy ) {
				$out[ $flag ] = null === $value ? $frozen[ $flag ] : 'yes' === $value;
			} elseif ( 'yes' === $value || 'no' === $value ) {
				$out[ $flag ] = 'yes' === $value;
			} else {
				$out[ $flag ] = null;
			}
		}

		return $out;
	}

	/**
	 * Settings of a widget saved by 1.3.0 with every detail explicit and the
	 * schema marker set: what the editor loads (and saves), so the panel
	 * shows what the widget shows. Rendering is the same before and after.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 * @param string               $context  Details context.
	 * @return array<string, mixed>
	 */
	protected function explicit_details( array $settings, string $context ): array {
		if ( ! $this->is_legacy_settings( $settings ) ) {
			return $settings;
		}

		$frozen = Details::neutral( $context );
		foreach ( Details::flags( $context ) as $flag ) {
			if ( array_key_exists( $flag, $settings ) ) {
				$settings[ $flag ] = 'yes' === $settings[ $flag ] ? 'yes' : 'no';
			} else {
				$settings[ $flag ] = $frozen[ $flag ] ? 'yes' : 'no';
			}
		}
		$settings['epm_schema'] = '2';

		return $settings;
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
				'description' => __( 'Podcast design follows global colors and fonts. Override this widget keeps its own styles. Switch back to Podcast design to inherit again; saved custom values are kept.', 'elementor-podcast-manager' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'global',
				'options' => [
					'global' => __( 'Use Podcast → Design styles', 'elementor-podcast-manager' ),
					'custom' => __( 'Override this widget', 'elementor-podcast-manager' ),
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
	 * Add the "Heading level" control for section widgets (show notes,
	 * chapters, transcript). The right level depends on the page outline.
	 *
	 * @return void
	 */
	protected function add_heading_tag_control(): void {
		$this->add_control(
			'heading_tag',
			[
				'label'       => __( 'Heading Level', 'elementor-podcast-manager' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'h3',
				'options'     => [
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
				],
				'description' => __( 'Pick the level that follows the heading above this widget, so screen reader users can navigate by headings.', 'elementor-podcast-manager' ),
			]
		);
	}

	/**
	 * Add a button shape control that sets --epm-button-radius (text
	 * buttons such as list "Play", speed, subscribe links and calls to
	 * action). Uses the same shapes as Podcast → Design.
	 *
	 * @param string $id       Control ID.
	 * @param string $selector Selector relative to {{WRAPPER}}.
	 * @return void
	 */
	protected function add_button_shape_control( string $id, string $selector ): void {
		$this->add_control(
			$id,
			[
				'label'                => __( 'Button Shape', 'elementor-podcast-manager' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => '',
				'options'              => [
					''        => __( 'Default', 'elementor-podcast-manager' ),
					'rounded' => __( 'Rounded', 'elementor-podcast-manager' ),
					'pill'    => __( 'Pill', 'elementor-podcast-manager' ),
					'square'  => __( 'Square', 'elementor-podcast-manager' ),
				],
				'selectors_dictionary' => \EPM\DesignSettings::button_shapes(),
				'selectors'            => [
					$selector => '--epm-button-radius: {{VALUE}};',
				],
				'condition'            => $this->custom_condition(),
			]
		);
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
