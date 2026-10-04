<?php
/**
 * Elementor integration (Layer 3): widget category + widget registration.
 *
 * Everything here runs only after Elementor is confirmed loaded
 * (see Plugin::init_elementor()), so direct references to \Elementor\*
 * classes are safe. The podcast engine (Layer 1) and the rendering
 * primitives (Layer 2) never depend on Elementor.
 *
 * @package EPM
 */

namespace EPM\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integration {

	/**
	 * Wire Elementor hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		add_action( 'wp_ajax_epm_topic_search', [ Controls\TopicSelectControl::class, 'ajax_search' ] );
		add_action( 'elementor/controls/register', [ $this, 'register_controls' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_script' ] );
	}

	/**
	 * Editor script: the widgets' "Use Podcast → Design defaults" button.
	 *
	 * @return void
	 */
	public function enqueue_editor_script(): void {
		$resets       = [];
		$descriptions = [];
		$titles       = [];
		foreach ( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $widget ) {
			if ( ! str_starts_with( $widget->get_name(), 'epm-' ) ) { continue; }
			$descriptions[ $widget->get_name() ] = $widget->task_description();
			$titles[ $widget->get_name() ] = $widget->get_title();
			foreach ( $widget->get_controls() as $control ) {
				if ( 'button' === ( $control['type'] ?? '' ) && str_starts_with( $control['event'] ?? '', 'epm:style:inherit:' ) ) {
					$resets[] = substr( $control['event'], strlen( 'epm:style:inherit:' ) );
				}
			}
		}
		wp_enqueue_script(
			'epm-elementor-editor',
			EPM_URL . 'admin/js/epm-elementor-editor.js',
			[ 'elementor-editor' ],
			EPM_VERSION,
			true
		);
		wp_enqueue_style( 'epm-elementor-editor', EPM_URL . 'admin/css/epm-elementor-editor.css', [ 'elementor-editor' ], EPM_VERSION );
		$post_id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$config = [
			'titles'       => $titles,
			'descriptions' => $descriptions,
			'resets'       => array_values( array_unique( $resets ) ),
			'starters'     => [],
			'documentId'   => $post_id,
			'episode'      => \EPM\EpisodePostType::CPT === get_post_type( $post_id ),
			'contextError' => __( 'Use the episode layout inside an episode document. On a show page, choose Show page or Episode archive.', 'elementor-podcast-manager' ),
			'insertError'  => __( 'The layout could not be inserted. Keep your document open and try again.', 'elementor-podcast-manager' ),
		];
		foreach ( [ 'show', 'archive', 'episode' ] as $kind ) {
			$config['starters'][ $kind ] = \EPM\AdminPages::starter_content( $kind )['elements'][0];
		}
		wp_add_inline_script( 'epm-elementor-editor', 'window.epmEditor = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Register the "Podcast" widget category.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elements manager.
	 * @return void
	 */
	public function register_category( \Elementor\Elements_Manager $elements_manager ): void {
		$elements_manager->add_category(
			'epm-podcast',
			[
				'title' => __( 'Podcast', 'elementor-podcast-manager' ),
				'icon'  => 'fa fa-podcast',
			]
		);
	}

	/**
	 * Register custom Elementor controls.
	 *
	 * @param \Elementor\Controls_Manager $controls_manager Controls manager.
	 * @return void
	 */
	public function register_controls( \Elementor\Controls_Manager $controls_manager ): void {
		$controls_manager->register( new Controls\EpisodeSelectControl() );
		$controls_manager->register( new Controls\TopicSelectControl() );
	}

	/**
	 * Register all podcast widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 * @return void
	 */
	public function register_widgets( \Elementor\Widgets_Manager $widgets_manager ): void {
		$widgets = new Widgets();
		$widgets->register( $widgets_manager );
	}
}
