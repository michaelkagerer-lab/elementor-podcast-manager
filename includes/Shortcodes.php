<?php
/**
 * Shortcodes (Layer 2). Thin wrappers over the same repositories,
 * rendering and player used by Elementor widgets.
 *
 * [podcast_player id="123" layout="editorial" share="yes"]
 * [podcast_latest sticky="yes"]
 * [podcast_episodes limit="10" layout="cards" topic="interviews,news" show_topics="yes"]
 * [podcast_video id="123"]
 * [podcast_latest_cta label="Listen now"]
 * [podcast_subscribe display="icon-text" rss="yes"]
 * [podcast_guest id="123"] [podcast_show_notes] [podcast_chapters] [podcast_transcript]
 *
 * Show notes, chapters and transcripts accept heading_tag="h2|h3|h4"
 * (default h3) so their headings fit the page outline.
 *
 * Episode components default to the current episode (the loop's episode
 * or the episode page) and accept id="123" or source="latest".
 *
 * Details: players and lists take every detail of their context as an
 * attribute named like the detail, show_volume="no", show_date="yes"
 * (see Details::flags(); [podcast_player] also keeps download="…" and
 * share="…"). An attribute that is present is the shortcode's own choice;
 * a missing one follows Podcast → Design → Details shown by default, and
 * without a site choice, what the shortcode showed in 1.3.0.
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
		add_shortcode( 'podcast_subscribe', [ $this, 'subscribe' ] );
		add_shortcode( 'podcast_guest', [ $this, 'guest' ] );
		add_shortcode( 'podcast_show_notes', [ $this, 'show_notes' ] );
		add_shortcode( 'podcast_chapters', [ $this, 'chapters' ] );
		add_shortcode( 'podcast_transcript', [ $this, 'transcript' ] );
		add_shortcode( 'podcast_video', [ $this, 'video' ] );
	}

	/**
	 * Resolve the episode for an episode-component shortcode.
	 *
	 * @param array $atts Attributes with id/source.
	 * @return array<string, mixed>|null
	 */
	private function episode_from_atts( array $atts ): ?array {
		$id     = absint( $atts['id'] ?? 0 );
		$source = $id > 0 ? 'specific' : sanitize_key( (string) ( $atts['source'] ?? 'current' ) );

		return epm()->renderer->resolve_episode( $source, $id );
	}

	/**
	 * Whether a yes/no shortcode attribute is on.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private function is_on( $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'yes', 'true', 'on' ], true );
	}

	/**
	 * The details a shortcode chose itself: every detail of the context
	 * whose attribute is present (null for the others: they inherit).
	 *
	 * @param mixed                 $raw     Attributes as written.
	 * @param string                $context Details context.
	 * @param array<string, string> $aliases Attribute => detail (older names).
	 * @return array<string, bool|null>
	 */
	private function explicit_details( $raw, string $context, array $aliases = [] ): array {
		$raw = is_array( $raw ) ? array_change_key_case( $raw, CASE_LOWER ) : [];
		$out = [];

		foreach ( Details::flags( $context ) as $flag ) {
			$out[ $flag ] = null;
		}
		foreach ( $aliases as $attribute => $flag ) {
			if ( isset( $raw[ $attribute ] ) && array_key_exists( $flag, $out ) ) {
				$out[ $flag ] = $this->is_on( $raw[ $attribute ] );
			}
		}
		foreach ( array_keys( $out ) as $flag ) {
			if ( isset( $raw[ $flag ] ) ) {
				$out[ $flag ] = $this->is_on( $raw[ $flag ] );
			}
		}

		return $out;
	}

	/**
	 * [podcast_subscribe display="icon-text|icon|text" rss="yes"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function subscribe( $atts ): string {
		$atts = shortcode_atts(
			[
				'display' => 'icon-text',
				'rss'     => 'yes',
			],
			$atts,
			'podcast_subscribe'
		);

		$display = sanitize_key( $atts['display'] );
		if ( ! in_array( $display, [ 'icon-text', 'icon', 'text' ], true ) ) {
			$display = 'icon-text';
		}

		Assets::enqueue_style();

		return epm()->renderer->subscribe_links(
			(array) epm()->settings->get( 'platform_links' ),
			[
				'display'  => $display,
				'show_rss' => $this->is_on( $atts['rss'] ),
			]
		);
	}

	/**
	 * [podcast_guest id="123" bio="yes"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function guest( $atts ): string {
		$atts    = shortcode_atts( [ 'id' => 0, 'source' => 'current', 'bio' => 'yes' ], $atts, 'podcast_guest' );
		$episode = $this->episode_from_atts( $atts );

		if ( ! $episode ) {
			return '';
		}

		Assets::enqueue_style();

		return epm()->renderer->guest( $episode, [ 'show_bio' => $this->is_on( $atts['bio'] ) ] );
	}

	/**
	 * [podcast_show_notes id="123" heading="Show notes" heading_tag="h3"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function show_notes( $atts ): string {
		$atts    = shortcode_atts( [ 'id' => 0, 'source' => 'current', 'heading' => __( 'Show notes', 'elementor-podcast-manager' ), 'heading_tag' => 'h3' ], $atts, 'podcast_show_notes' );
		$episode = $this->episode_from_atts( $atts );

		if ( ! $episode ) {
			return '';
		}

		Assets::enqueue_style();

		return epm()->renderer->show_notes(
			$episode,
			[
				'heading'     => sanitize_text_field( $atts['heading'] ),
				'heading_tag' => sanitize_key( $atts['heading_tag'] ),
			]
		);
	}

	/**
	 * [podcast_chapters id="123" heading="Chapters" heading_tag="h3"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function chapters( $atts ): string {
		$atts    = shortcode_atts( [ 'id' => 0, 'source' => 'current', 'heading' => __( 'Chapters', 'elementor-podcast-manager' ), 'heading_tag' => 'h3' ], $atts, 'podcast_chapters' );
		$episode = $this->episode_from_atts( $atts );

		if ( ! $episode ) {
			return '';
		}

		// Chapters seek the episode's audio: needs the player engine.
		Assets::enqueue();

		return epm()->renderer->chapters(
			$episode,
			[
				'heading'     => sanitize_text_field( $atts['heading'] ),
				'heading_tag' => sanitize_key( $atts['heading_tag'] ),
			]
		);
	}

	/**
	 * [podcast_transcript id="123" heading="Transcript" heading_tag="h3" collapsible="no"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function transcript( $atts ): string {
		$atts    = shortcode_atts( [ 'id' => 0, 'source' => 'current', 'heading' => __( 'Transcript', 'elementor-podcast-manager' ), 'heading_tag' => 'h3', 'collapsible' => 'no' ], $atts, 'podcast_transcript' );
		$episode = $this->episode_from_atts( $atts );

		if ( ! $episode ) {
			return '';
		}

		Assets::enqueue_style();

		return epm()->renderer->transcript(
			$episode,
			[
				'heading'     => sanitize_text_field( $atts['heading'] ),
				'heading_tag' => sanitize_key( $atts['heading_tag'] ),
				'collapsible' => $this->is_on( $atts['collapsible'] ),
			]
		);
	}

	/**
	 * [podcast_player id="123" layout="editorial" source="current" sticky="no"]
	 *
	 * Details as attributes (show_volume="no", …; download and share are
	 * kept as short names). Missing ones follow the site.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function player( $atts ): string {
		$raw  = $atts;
		$atts = shortcode_atts(
			[
				'id'     => 0,
				'source' => 'current',
				'layout' => '',
				'sticky' => 'no',
			],
			$atts,
			'podcast_player'
		);

		$source  = (int) $atts['id'] > 0 ? 'specific' : sanitize_key( $atts['source'] );
		$episode = epm()->renderer->resolve_episode( $source, (int) $atts['id'] );

		if ( ! $episode ) {
			return '';
		}

		$args = Details::resolve(
			'player',
			$this->explicit_details(
				$raw,
				'player',
				[
					'download' => 'show_download',
					'share'    => 'show_share',
				]
			),
			'shortcode'
		);

		$args['sticky'] = $this->is_on( $atts['sticky'] );
		if ( '' !== $atts['layout'] ) {
			$args['layout'] = sanitize_key( $atts['layout'] );
		}

		Assets::enqueue();

		return epm()->renderer->player( $episode, $args );
	}

	/**
	 * [podcast_latest layout="artwork" sticky="no"]
	 *
	 * The newest episode with audio. Details as attributes, like
	 * [podcast_player]; sticky="yes" opens the sticky player when playback
	 * starts here (off by default, as in 1.3.0).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function latest( $atts ): string {
		$raw  = $atts;
		$atts = shortcode_atts(
			[
				'layout' => '',
				'sticky' => 'no',
			],
			$atts,
			'podcast_latest'
		);

		$episode = epm()->renderer->resolve_episode( 'latest' );

		if ( ! $episode ) {
			return '';
		}

		$args = Details::resolve(
			'latest',
			$this->explicit_details(
				$raw,
				'latest',
				[
					'download' => 'show_download',
					'share'    => 'show_share',
				]
			),
			'shortcode'
		);

		$args['sticky'] = $this->is_on( $atts['sticky'] );
		if ( '' !== $atts['layout'] ) {
			$args['layout'] = sanitize_key( $atts['layout'] );
		}

		Assets::enqueue();

		return epm()->renderer->player( $episode, $args );
	}

	/**
	 * [podcast_video id="123"]
	 *
	 * The episode's video (YouTube, Vimeo or a video file) as a
	 * click-to-load facade: nothing loads from the platform before play.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function video( $atts ): string {
		$atts    = shortcode_atts( [ 'id' => 0, 'source' => 'current', 'note' => 'yes' ], $atts, 'podcast_video' );
		$episode = $this->episode_from_atts( $atts );

		if ( ! $episode ) {
			return '';
		}

		Assets::enqueue();

		return epm()->renderer->video( $episode, [ 'show_note' => $this->is_on( $atts['note'] ) ] );
	}

	/**
	 * [podcast_episodes limit="10" layout="list" orderby="date" order="DESC"
	 *   season="1" topic="slug,slug" show_topics="no"]
	 *
	 * topic limits the list to episodes with any of the given topics;
	 * show_topics adds topic chips to rows and cards. Details as attributes
	 * (show_artwork, show_excerpt, …); missing ones follow the site.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function episodes( $atts ): string {
		$raw  = $atts;
		$atts = shortcode_atts(
			[
				'limit'   => 10,
				'layout'  => '',
				'orderby' => 'date',
				'order'   => 'DESC',
				'season'  => 0,
				'topic'   => '',
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

		$topic_args = Renderer::topic_query_args( Renderer::topic_slugs( $atts['topic'] ) );
		$query_args = array_merge( $query_args, $topic_args );

		$posts = epm()->episodes->get_episodes( $query_args );

		$layout = '' !== $atts['layout'] ? sanitize_key( $atts['layout'] ) : (string) epm()->design->get( 'default_episode_layout' );
		$rows   = ! in_array( $layout, [ 'cards', 'grid' ], true );

		$args = Details::resolve( 'list', $this->explicit_details( $raw, 'list' ), $rows ? 'shortcode-rows' : 'shortcode' );

		// A season or topic filter that matches nothing gets the "selection"
		// empty state with a link to all episodes.
		$args['filtered'] = isset( $query_args['season'] ) || ! empty( $topic_args );
		if ( '' !== $atts['layout'] ) {
			$args['layout'] = $layout;
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
