<?php
/**
 * Elementor widget registry (Layer 3).
 *
 * Registers every podcast widget with the Elementor widgets manager.
 * Rendering itself stays in \EPM\Renderer — widgets only translate
 * Elementor controls into renderer arguments.
 *
 * @package EPM
 */

namespace EPM\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Widgets {

	/**
	 * Register all widgets.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 * @return void
	 */
	public function register( \Elementor\Widgets_Manager $manager ): void {
		$widgets = [
			Widgets\PodcastPlayerWidget::class,
			Widgets\EpisodeListWidget::class,
			Widgets\LatestEpisodeWidget::class,
			Widgets\PodcastHeroWidget::class,
			Widgets\EpisodeHeaderWidget::class,
			Widgets\EpisodeMetadataWidget::class,
			Widgets\GuestWidget::class,
			Widgets\SubscribeLinksWidget::class,
			Widgets\TranscriptWidget::class,
			Widgets\ShowNotesWidget::class,
			Widgets\ChaptersWidget::class,
			Widgets\EpisodeVideoWidget::class,
		];

		foreach ( $widgets as $widget_class ) {
			$manager->register( new $widget_class() );
		}
	}
}
