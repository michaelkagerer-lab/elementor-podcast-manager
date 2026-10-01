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

		add_action( 'elementor/elements/categories', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		add_action( 'elementor/controls/register', [ $this, 'register_controls' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_script' ] );
	}

	/**
	 * Editor script: the widgets' "Use Podcast → Design defaults" button.
	 *
	 * @return void
	 */
	public function enqueue_editor_script(): void {
		wp_enqueue_script(
			'epm-elementor-editor',
			EPM_URL . 'admin/js/epm-elementor-editor.js',
			[ 'elementor-editor' ],
			EPM_VERSION,
			true
		);
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
