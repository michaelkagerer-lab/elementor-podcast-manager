<?php
/**
 * Shortcodes (Layer 2). Thin wrappers over the same repositories,
 * rendering and player used by Elementor widgets.
 *
 * [podcast_player id="123" layout="editorial"]
 * [podcast_latest]
 * [podcast_episodes limit="10" layout="cards"]
 * [podcast_latest_cta label="Listen now"]
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'podcast_player', [ $this, 'player' ] );
		add_shortcode( 'podcast_latest', [ $this, 'latest' ] );
		add_shortcode( 'podcast_episodes', [ $this, 'episodes' ] );
		add_shortcode( 'podcast_latest_cta', [ $this, 'latest_cta' ] );
	}

	/**
	 * [podcast_player id="123" layout="editorial" source="current"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function player( $atts ): string {
		$atts = shortcode_atts(
			[
				'id'     => 0,
				'source' => 'current',
				'layout' => '',
			],
			$atts,
			'podcast_player'
		);

		$source = (int) $atts['id'] > 0 ? 'specific' : sanitize_key( $atts['source'] );
		$episode = epm()->renderer->resolve_episode( $source, (int) $atts['id'] );

		if ( ! $episode ) {
			return '';
		}

		$args = [];
		if ( '' !== $atts['layout'] ) {
			$args['layout'] = sanitize_key( $atts['layout'] );
		}

		Assets::enqueue();

		return epm()->renderer->player( $episode, $args );
	}

	/**
	 * [podcast_latest layout="artwork"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function latest( $atts ): string {
		$atts = shortcode_atts(
			[
				'layout' => '',
			],
			$atts,
			'podcast_latest'
		);

		$episode = epm()->renderer->resolve_episode( 'latest' );

		if ( ! $episode ) {
			return '';
		}

		$args = [
			'show_artwork'     => true,
			'show_description' => true,
		];
		if ( '' !== $atts['layout'] ) {
			$args['layout'] = sanitize_key( $atts['layout'] );
		}

		Assets::enqueue();

		return epm()->renderer->player( $episode, $args );
	}

	/**
	 * [podcast_episodes limit="10" layout="list" orderby="date" order="DESC"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function episodes( $atts ): string {
		$atts = shortcode_atts(
			[
				'limit'   => 10,
				'layout'  => '',
				'orderby' => 'date',
				'order'   => 'DESC',
				'season'  => 0,
			],
			$atts,
			'podcast_episodes'
		);

		$query_args = [
			'posts_per_page' => max( 1, min( 100, (int) $atts['limit'] ) ),
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( $atts['order'] ) ? 'ASC' : 'DESC',
		];

		if ( (int) $atts['season'] > 0 ) {
			$query_args['season'] = (int) $atts['season'];
		}

		$posts = epm()->episodes->get_episodes( $query_args );

		$args = [];
		if ( '' !== $atts['layout'] ) {
			$args['layout'] = sanitize_key( $atts['layout'] );
		}

		Assets::enqueue();

		return epm()->renderer->episode_list( $posts, $args );
	}

	/**
	 * [podcast_latest_cta label="Listen now"]
	 *
	 * Renders the automatic latest-episode CTA button when enabled.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function latest_cta( $atts ): string {
		$atts = shortcode_atts(
			[
				'label' => '',
			],
			$atts,
			'podcast_latest_cta'
		);

		Assets::enqueue_style();

		return epm()->renderer->latest_cta(
			[
				'label' => sanitize_text_field( $atts['label'] ),
			]
		);
	}
}
