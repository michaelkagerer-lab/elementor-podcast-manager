<?php
/**
 * Shared frontend rendering primitives (Layer 2).
 *
 * ONE player engine, ONE set of episode rendering primitives. Widgets,
 * shortcodes and templates all call these methods — never duplicate markup.
 * Styling flows through CSS custom properties (--epm-*) so Elementor
 * controls and Global Podcast Styles compose without specificity wars.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Renderer {

	/**
	 * Resolve an episode from a data source.
	 *
	 * Public contexts (widgets, shortcodes, frontend) only ever receive
	 * publicly-visible episodes. Authorized preview (Elementor editor,
	 * explicit preview requests) may resolve restricted episodes for
	 * users who can edit them — never for anonymous visitors.
	 *
	 * @param string $source  current|specific|latest.
	 * @param int    $post_id Post ID for "specific".
	 * @param string $context public|preview|auto. "auto" upgrades to preview
	 *                        inside the Elementor editor (authorized users only).
	 * @return array<string, mixed>|null
	 */
	public function resolve_episode( string $source = 'current', int $post_id = 0, string $context = 'auto' ): ?array {
		// Authorized preview: the Elementor editor, or WordPress's native
		// post preview. get_preview_data() still requires edit_post for
		// anything that is not public, so visitors never see restricted data.
		$preview_request = $this->is_elementor_preview() || is_preview();

		if ( 'auto' === $context ) {
			$context = $preview_request ? 'preview' : 'public';
		}

		$allow_preview = 'preview' === $context && $preview_request;

		$get = $allow_preview
			? [ epm()->episodes, 'get_preview_data' ]
			: [ epm()->episodes, 'get_public_data' ];

		switch ( $source ) {
			case 'specific':
				return $post_id > 0 ? call_user_func( $get, $post_id ) : null;

			case 'latest':
				$latest = epm()->episodes->get_latest( true );
				return $latest ? call_user_func( $get, $latest ) : null;

			case 'current':
			default:
				// The loop's post wins (Loop Grid items, related-episode
				// loops, shortcodes inside a loop); on a single episode page
				// it is the queried episode anyway.
				$loop_post = get_post();
				if ( $loop_post instanceof \WP_Post && EpisodePostType::CPT === $loop_post->post_type ) {
					return call_user_func( $get, $loop_post );
				}
				$queried = get_queried_object();
				if ( $queried instanceof \WP_Post && EpisodePostType::CPT === $queried->post_type ) {
					return call_user_func( $get, $queried );
				}
				// Elementor editor preview fallback: latest episode (preview-aware).
				if ( $allow_preview ) {
					$latest = epm()->episodes->get_latest( true );
					return $latest ? call_user_func( $get, $latest ) : null;
				}
				return null;
		}
	}

	/**
	 * Whether we're inside the Elementor editor/preview.
	 *
	 * @return bool
	 */
	public function is_elementor_preview(): bool {
		return epm()->has_elementor()
			&& class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * Build an inline style attribute from CSS var overrides.
	 *
	 * @param array<string, string> $vars Var name => value (e.g. '--epm-accent' => '#fff').
	 * @return string
	 */
	public function style_vars( array $vars ): string {
		$parts = [];
		foreach ( $vars as $name => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value || strpos( $name, '--epm-' ) !== 0 ) {
				continue;
			}
			$parts[] = $name . ':' . $value;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return ' style="' . esc_attr( implode( ';', $parts ) ) . '"';
	}

	/**
	 * Episode artwork.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param string               $size Image size.
	 * @param string               $class Extra class.
	 * @return string
	 */
	public function artwork( array $episode, string $size = 'medium', string $class = '' ): string {
		$id = (int) ( $episode['artwork_id'] ?? 0 );

		if ( $id <= 0 ) {
			return '';
		}

		$img = wp_get_attachment_image(
			$id,
			$size,
			false,
			[
				'class'   => trim( 'epm-artwork ' . $class ),
				'alt'     => sprintf(
					/* translators: %s: episode title */
					__( 'Artwork for %s', 'elementor-podcast-manager' ),
					$episode['title'] ?? ''
				),
				'loading' => 'lazy',
			]
		);

		return $img ? (string) $img : '';
	}

	/**
	 * Episode metadata line.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<int, string>   $fields Fields to include.
	 * @param string               $separator Separator.
	 * @return string
	 */
	public function metadata( array $episode, array $fields = [], string $separator = ' · ' ): string {
		if ( empty( $fields ) ) {
			$fields = [ 'episode_number', 'date', 'duration', 'guest' ];
		}

		$items = [];

		foreach ( $fields as $field ) {
			$value = $this->metadata_value( $episode, $field );
			if ( '' !== $value ) {
				$items[] = '<span class="epm-meta__item epm-meta__item--' . esc_attr( $field ) . '">' . $value . '</span>';
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		return '<p class="epm-meta">' . implode( '<span class="epm-meta__sep" aria-hidden="true">' . esc_html( $separator ) . '</span>', $items ) . '</p>';
	}

	/**
	 * Single metadata field value (escaped).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param string               $field Field key.
	 * @return string
	 */
	public function metadata_value( array $episode, string $field ): string {
		switch ( $field ) {
			case 'episode_number':
				$no = (string) ( $episode['episode_number'] ?? '' );
				if ( '' === $no ) {
					return '';
				}
				return esc_html(
					sprintf(
						/* translators: %s: episode number */
						__( 'Episode %s', 'elementor-podcast-manager' ),
						$no
					)
				);

			case 'season':
				$season = (string) ( $episode['season_number'] ?? '' );
				/* translators: %s: season number */
				return '' === $season ? '' : esc_html( sprintf( __( 'Season %s', 'elementor-podcast-manager' ), $season ) );

			case 'date':
				return esc_html( (string) ( $episode['date'] ?? '' ) );

			case 'duration':
				return esc_html( (string) ( $episode['duration'] ?? '' ) );

			case 'guest':
				$guest = (string) ( $episode['guest_name'] ?? '' );
				if ( '' === $guest ) {
					return '';
				}
				$company = (string) ( $episode['guest_company'] ?? '' );
				if ( '' !== $company ) {
					$guest .= ', ' . $company;
				}
				return esc_html( $guest );

			case 'type':
				$type = (string) ( $episode['episode_type'] ?? '' );
				$labels = [
					'full'    => __( 'Full episode', 'elementor-podcast-manager' ),
					'trailer' => __( 'Trailer', 'elementor-podcast-manager' ),
					'bonus'   => __( 'Bonus', 'elementor-podcast-manager' ),
				];
				return esc_html( $labels[ $type ] ?? '' );

			default:
				return esc_html( (string) apply_filters( 'epm_episode_metadata', '', $field, $episode ) );
		}
	}

	/**
	 * Guest block.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options: show_image, show_role, show_company, show_bio.
	 * @return string Empty when no guest data.
	 */
	public function guest( array $episode, array $args = [] ): string {
		$args = wp_parse_args(
			$args,
			[
				'show_image'   => true,
				'show_role'    => true,
				'show_company' => true,
				'show_bio'     => false,
			]
		);

		$name = (string) ( $episode['guest_name'] ?? '' );

		if ( '' === $name ) {
			return '';
		}

		$out = '<div class="epm-guest">';

		$image_id = (int) ( $episode['guest_image_id'] ?? 0 );
		if ( $args['show_image'] && $image_id > 0 ) {
			$img = wp_get_attachment_image(
				$image_id,
				'thumbnail',
				false,
				[ 'class' => 'epm-guest__image', 'alt' => $name, 'loading' => 'lazy' ]
			);
			if ( $img ) {
				$out .= $img;
			}
		}

		$out .= '<div class="epm-guest__info">';
		$out .= '<p class="epm-guest__name">' . esc_html( $name ) . '</p>';

		$role    = (string) ( $episode['guest_role'] ?? '' );
		$company = (string) ( $episode['guest_company'] ?? '' );

		if ( $args['show_role'] && '' !== $role ) {
			$out .= '<p class="epm-guest__role">' . esc_html( $role ) . '</p>';
		}
		if ( $args['show_company'] && '' !== $company ) {
			$out .= '<p class="epm-guest__company">' . esc_html( $company ) . '</p>';
		}
		if ( $args['show_bio'] && '' !== (string) ( $episode['guest_bio'] ?? '' ) ) {
			$out .= '<div class="epm-guest__bio">' . wp_kses_post( wpautop( (string) $episode['guest_bio'] ) ) . '</div>';
		}

		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Chapters list. Clicking a chapter seeks the nearest player.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options: heading.
	 * @return string
	 */
	public function chapters( array $episode, array $args = [] ): string {
		$chapters = $episode['chapters'] ?? [];

		if ( empty( $chapters ) ) {
			return '';
		}

		$heading = $args['heading'] ?? __( 'Chapters', 'elementor-podcast-manager' );

		$out = '<div class="epm-chapters" data-epm-chapters data-epm-episode-id="' . esc_attr( (string) ( $episode['id'] ?? 0 ) ) . '" data-epm-src="' . esc_url( (string) ( $episode['audio_url'] ?? '' ) ) . '" data-epm-title="' . esc_attr( (string) ( $episode['title'] ?? '' ) ) . '">';
		if ( '' !== (string) $heading ) {
			$out .= '<h3 class="epm-chapters__heading">' . esc_html( (string) $heading ) . '</h3>';
		}
		$out .= '<ol class="epm-chapters__list">';

		foreach ( $chapters as $chapter ) {
			$out .= '<li class="epm-chapters__item">';
			/* translators: %s: chapter timestamp, e.g. 12:30 */
			$out .= '<button type="button" class="epm-chapters__time" data-epm-seek="' . esc_attr( (string) $chapter['seconds'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Skip to %s', 'elementor-podcast-manager' ), $chapter['time'] ) ) . '">';
			$out .= esc_html( $chapter['time'] );
			$out .= '</button>';
			$out .= '<span class="epm-chapters__title">' . esc_html( $chapter['title'] ) . '</span>';
			if ( '' !== (string) ( $chapter['url'] ?? '' ) ) {
				$out .= ' <a class="epm-chapters__link" href="' . esc_url( $chapter['url'] ) . '">' . esc_html__( 'Link', 'elementor-podcast-manager' ) . '</a>';
			}
			$out .= '</li>';
		}

		$out .= '</ol></div>';

		return $out;
	}

	/**
	 * Transcript block. Semantic, indexable HTML.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options: heading, collapsible.
	 * @return string
	 */
	public function transcript( array $episode, array $args = [] ): string {
		$transcript = trim( (string) ( $episode['transcript'] ?? '' ) );

		if ( '' === $transcript ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			[
				'heading'     => __( 'Transcript', 'elementor-podcast-manager' ),
				'collapsible' => false,
			]
		);

		$content = '<div class="epm-transcript__content">' . wp_kses_post( wpautop( $transcript ) ) . '</div>';

		if ( $args['collapsible'] ) {
			return '<details class="epm-transcript epm-transcript--collapsible">'
				. '<summary class="epm-transcript__heading">' . esc_html( (string) $args['heading'] ) . '</summary>'
				. $content
				. '</details>';
		}

		$out = '<div class="epm-transcript">';
		if ( '' !== (string) $args['heading'] ) {
			$out .= '<h3 class="epm-transcript__heading">' . esc_html( (string) $args['heading'] ) . '</h3>';
		}
		$out .= $content . '</div>';

		return $out;
	}

	/**
	 * Render episode show notes (rich HTML, semantic preserved).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array                $args  Optional: heading.
	 * @return string
	 */
	public function show_notes( array $episode, array $args = [] ): string {
		$notes = trim( (string) ( $episode['show_notes'] ?? '' ) );

		if ( '' === $notes ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			[
				'heading' => __( 'Show Notes', 'elementor-podcast-manager' ),
			]
		);

		$out = '<div class="epm-show-notes">';
		if ( '' !== (string) $args['heading'] ) {
			$out .= '<h3 class="epm-show-notes__heading">' . esc_html( (string) $args['heading'] ) . '</h3>';
		}
		$out .= '<div class="epm-show-notes__content">' . wp_kses_post( wpautop( $notes ) ) . '</div>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render the automatic latest-episode CTA button.
	 *
	 * Only renders when the CTA is enabled in Podcast Settings and a
	 * publicly-visible latest episode exists.
	 *
	 * @param array $args Optional: label, class.
	 * @return string
	 */
	public function latest_cta( array $args = [] ): string {
		if ( empty( epm()->settings->get( 'latest_cta_enabled' ) ) ) {
			return '';
		}

		$latest = epm()->episodes->get_latest( true );
		if ( ! $latest ) {
			return '';
		}

		$episode = epm()->episodes->get_public_data( $latest );
		if ( ! $episode ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			[
				'label' => (string) epm()->settings->get( 'latest_cta_label' ),
				'class' => 'epm-cta epm-latest-cta',
			]
		);

		$label = '' !== trim( (string) $args['label'] )
			? (string) $args['label']
			: __( 'Listen to the latest episode', 'elementor-podcast-manager' );

		return '<a class="' . esc_attr( (string) $args['class'] ) . '" href="' . esc_url( (string) $episode['url'] ) . '">'
			. esc_html( $label )
			. '</a>';
	}

	/**
	 * Subscribe / platform links.
	 *
	 * @param array<int, array{label: string, url: string, service: string}> $links Links.
	 * @param array<string, mixed> $args Options: display (icon|text|icon-text), show_rss.
	 * @return string
	 */
	public function subscribe_links( array $links, array $args = [] ): string {
		$args = wp_parse_args(
			$args,
			[
				'display'  => 'icon-text',
				'show_rss' => true,
			]
		);

		if ( $args['show_rss'] ) {
			$links[] = [
				'label'   => __( 'RSS Feed', 'elementor-podcast-manager' ),
				'url'     => Feed::url(),
				'service' => 'rss',
			];
		}

		if ( empty( $links ) ) {
			return '';
		}

		$out = '<ul class="epm-subscribe epm-subscribe--' . esc_attr( $args['display'] ) . '">';

		foreach ( $links as $link ) {
			$service = sanitize_key( $link['service'] ?? 'custom' );
			$label   = '' !== (string) ( $link['label'] ?? '' ) ? (string) $link['label'] : ucfirst( $service );

			$out .= '<li class="epm-subscribe__item epm-subscribe__item--' . esc_attr( $service ) . '">';
			$out .= '<a class="epm-subscribe__link" href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener">';

			if ( 'text' !== $args['display'] ) {
				$out .= '<span class="epm-subscribe__icon" aria-hidden="true">' . $this->service_icon( $service ) . '</span>';
			}
			if ( 'icon' !== $args['display'] ) {
				$out .= '<span class="epm-subscribe__label">' . esc_html( $label ) . '</span>';
			} else {
				$out .= '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
			}

			$out .= '</a></li>';
		}

		$out .= '</ul>';

		return $out;
	}

	/**
	 * Neutral SVG icon for a platform service.
	 *
	 * @param string $service Service key.
	 * @return string SVG markup.
	 */
	public function service_icon( string $service ): string {
		// Neutral generic icons; services share shapes, no brand colors.
		$paths = [
			'spotify' => '<circle cx="12" cy="12" r="9"/><path d="M8 10.5c2.7-.8 5.6-.4 7.8 1M8.2 13.2c2.2-.6 4.4-.3 6.2.9M8.4 15.7c1.7-.5 3.3-.2 4.7.7"/>',
			'apple'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c1-1 5-1 6 0"/>',
			'youtube' => '<rect x="3" y="6" width="18" height="12" rx="3"/><path d="M10.5 9.8v4.4L14.5 12z"/>',
			'rss'     => '<circle cx="6" cy="18" r="2"/><path d="M4 10a8 8 0 0 1 8 8M4 4a14 14 0 0 1 14 14"/>',
			'custom'  => '<circle cx="12" cy="12" r="9"/><path d="M10 9l5 3-5 3z"/>',
		];

		$path = $paths[ $service ] ?? $paths['custom'];

		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
	}

	/**
	 * Play / pause SVG icons.
	 *
	 * @param string $which play|pause.
	 * @return string
	 */
	public function play_icon( string $which = 'play' ): string {
		if ( 'pause' === $which ) {
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>';
		}

		return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.87l10-6.5a1 1 0 0 0 0-1.74l-10-6.5A1 1 0 0 0 8 5.5z"/></svg>';
	}

	/**
	 * THE player. One engine, layouts are configurations.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options:
	 *   layout, show_* flags, style_vars, sticky (bool), player_id.
	 * @return string
	 */
	public function player( array $episode, array $args = [] ): string {
		if ( empty( $episode['has_audio'] ) ) {
			return '<p class="epm-player__missing">' . esc_html__( 'Audio is not available for this episode yet.', 'elementor-podcast-manager' ) . '</p>';
		}

		$design = epm()->design;

		$args = wp_parse_args(
			$args,
			[
				'layout'               => $design->get( 'default_player_layout' ),
				'show_artwork'         => true,
				'show_episode_label'   => true,
				'show_title'           => true,
				'show_episode_number'  => false,
				'show_season'          => false,
				'show_guest'           => true,
				'show_description'     => false,
				'show_date'            => false,
				'show_duration'        => true,
				'show_playback_speed'  => true,
				'show_skip_backward'   => true,
				'show_skip_forward'    => true,
				'show_volume'          => true,
				'show_download'        => false,
				'show_chapters_link'   => false,
				'show_platform_links'  => false,
				'sticky'               => false,
				'style_vars'           => [],
				'player_id'            => 'epm-player-' . (int) ( $episode['id'] ?? 0 ) . '-' . wp_unique_id(),
			]
		);

		$layout = sanitize_key( (string) $args['layout'] );
		$layouts = [ 'minimal', 'compact', 'editorial', 'artwork', 'full' ];
		if ( '' === $layout ) {
			$layout = sanitize_key( (string) $design->get( 'default_player_layout' ) );
		}
		if ( ! in_array( $layout, $layouts, true ) ) {
			$layout = 'minimal';
		}

		if ( $args['sticky'] ) {
			Assets::request_sticky_player();
		}
		Assets::mark_player_used();

		$classes = [ 'epm-player', 'epm-player--' . $layout ];
		$classes = apply_filters( 'epm_player_classes', $classes, $episode, $args );

		$duration_seconds = (int) ( $episode['duration_seconds'] ?? 0 );

		$out = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"'
			. ' data-epm-player'
			. ' id="' . esc_attr( $args['player_id'] ) . '"'
			. ' data-epm-episode-id="' . esc_attr( (string) ( $episode['id'] ?? 0 ) ) . '"'
			. ' data-epm-duration="' . esc_attr( (string) $duration_seconds ) . '"'
			. ' data-epm-title="' . esc_attr( (string) ( $episode['title'] ?? '' ) ) . '"'
			. ' data-epm-artwork="' . esc_url( $this->artwork_url( $episode, 'medium' ) ) . '"'
			. ' data-epm-src="' . esc_url( (string) $episode['audio_url'] ) . '"'
			. $this->style_vars( (array) $args['style_vars'] )
			. '>';

		// Hidden native audio element — the single engine underneath.
		$out .= '<audio preload="metadata" src="' . esc_url( (string) $episode['audio_url'] ) . '"></audio>';

		// Artwork.
		if ( $args['show_artwork'] ) {
			$art = $this->artwork( $episode, 'medium', 'epm-player__artwork-img' );
			if ( '' !== $art ) {
				$out .= '<div class="epm-player__artwork">' . $art . '</div>';
			}
		}

		$out .= '<div class="epm-player__main">';

		// Header: label, title, meta.
		$out .= '<div class="epm-player__header">';
		if ( $args['show_episode_label'] ) {
			$label = $this->episode_label( $episode, (bool) $args['show_episode_number'], (bool) $args['show_season'] );
			if ( '' !== $label ) {
				$out .= '<p class="epm-player__label">' . $label . '</p>';
			}
		}
		if ( $args['show_title'] ) {
			$out .= '<p class="epm-player__title">' . esc_html( (string) ( $episode['title'] ?? '' ) ) . '</p>';
		}
		$meta_fields = [];
		if ( $args['show_guest'] ) {
			$meta_fields[] = 'guest';
		}
		if ( $args['show_date'] ) {
			$meta_fields[] = 'date';
		}
		if ( $args['show_duration'] ) {
			$meta_fields[] = 'duration';
		}
		if ( ! empty( $meta_fields ) ) {
			$out .= $this->metadata( $episode, $meta_fields );
		}
		$out .= '</div>';

		// Controls row.
		$out .= '<div class="epm-player__controls">';

		$out .= '<button type="button" class="epm-player__play" data-epm-play aria-label="' . esc_attr__( 'Play episode', 'elementor-podcast-manager' ) . '"'
			. ' data-label-play="' . esc_attr__( 'Play episode', 'elementor-podcast-manager' ) . '"'
			. ' data-label-pause="' . esc_attr__( 'Pause episode', 'elementor-podcast-manager' ) . '">'
			. '<span class="epm-player__icon-play">' . $this->play_icon( 'play' ) . '</span>'
			. '<span class="epm-player__icon-pause">' . $this->play_icon( 'pause' ) . '</span>'
			. '</button>';

		if ( $args['show_skip_backward'] ) {
			$out .= '<button type="button" class="epm-player__skip" data-epm-seek-rel="-15" aria-label="' . esc_attr__( 'Skip back 15 seconds', 'elementor-podcast-manager' ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M11 8l-4 4 4 4M18 8l-4 4 4 4"/></svg>'
				. '<span aria-hidden="true">15</span></button>';
		}

		// Timeline.
		$out .= '<div class="epm-player__timeline-wrap">';
		$out .= '<div class="epm-player__timeline" data-epm-timeline role="slider" tabindex="0"'
			. ' aria-label="' . esc_attr__( 'Seek', 'elementor-podcast-manager' ) . '"'
			. ' aria-valuemin="0" aria-valuemax="' . esc_attr( (string) $duration_seconds ) . '" aria-valuenow="0">';
		$out .= '<div class="epm-player__track"><div class="epm-player__progress" data-epm-progress></div></div>';
		$out .= '<div class="epm-player__handle" data-epm-handle></div>';
		$out .= '</div>';
		$out .= '<div class="epm-player__times"><span data-epm-current>0:00</span><span data-epm-total>' . esc_html( Episodes::format_duration( $duration_seconds ) ) . '</span></div>';
		$out .= '</div>';

		if ( $args['show_skip_forward'] ) {
			$out .= '<button type="button" class="epm-player__skip" data-epm-seek-rel="30" aria-label="' . esc_attr__( 'Skip forward 30 seconds', 'elementor-podcast-manager' ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M13 8l4 4-4 4M6 8l4 4-4 4"/></svg>'
				. '<span aria-hidden="true">30</span></button>';
		}

		$out .= '</div>';

		// Secondary row: speed, volume, download.
		$secondary = '';
		if ( $args['show_playback_speed'] ) {
			$secondary .= '<button type="button" class="epm-player__speed" data-epm-speed aria-label="' . esc_attr__( 'Playback speed', 'elementor-podcast-manager' ) . '">1×</button>';
		}
		if ( $args['show_volume'] ) {
			$secondary .= '<label class="epm-player__volume"><span class="screen-reader-text">' . esc_html__( 'Volume', 'elementor-podcast-manager' ) . '</span>'
				. '<input type="range" min="0" max="1" step="0.05" value="1" data-epm-volume aria-label="' . esc_attr__( 'Volume', 'elementor-podcast-manager' ) . '" /></label>';
		}
		if ( $args['show_download'] && ! empty( $episode['audio_url'] ) ) {
			$secondary .= '<a class="epm-player__download" href="' . esc_url( (string) $episode['audio_url'] ) . '" download aria-label="' . esc_attr__( 'Download episode', 'elementor-podcast-manager' ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 4v12m0 0l-4-4m4 4l4-4M5 20h14"/></svg></a>';
		}

		if ( '' !== $secondary ) {
			$out .= '<div class="epm-player__secondary">' . $secondary . '</div>';
		}

		// Description (full layout).
		if ( $args['show_description'] ) {
			$desc = '' !== (string) ( $episode['short_description'] ?? '' )
				? (string) $episode['short_description']
				: wp_trim_words( wp_strip_all_tags( (string) ( $episode['description'] ?? '' ) ), 40 );
			if ( '' !== $desc ) {
				$out .= '<div class="epm-player__description">' . esc_html( $desc ) . '</div>';
			}
		}

		// Chapters toggle (no extra JS: <details> + data-epm-seek buttons).
		if ( $args['show_chapters_link'] && ! empty( $episode['chapters'] ) ) {
			$out .= '<details class="epm-player__chapters"><summary class="epm-player__chapters-toggle">'
				. esc_html__( 'Chapters', 'elementor-podcast-manager' )
				. '</summary>'
				. $this->chapters( $episode, [ 'heading' => '' ] )
				. '</details>';
		}

		// Platform links row.
		if ( $args['show_platform_links'] ) {
			$links = $this->merge_platform_links( $episode );
			if ( ! empty( $links ) ) {
				$out .= '<div class="epm-player__platforms">' . $this->subscribe_links( $links, [ 'display' => 'icon-text', 'show_rss' => false ] ) . '</div>';
			}
		}

		$out .= '</div>'; // .epm-player__main
		$out .= '</div>'; // .epm-player

		return apply_filters( 'epm_player_html', $out, $episode, $args );
	}

	/**
	 * Merge episode platform URLs with podcast-level platform links (deduped).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<int, array{label: string, url: string, service: string}>
	 */
	public function merge_platform_links( array $episode ): array {
		$links = array_merge(
			(array) epm()->settings->get( 'platform_links' ),
			(array) ( $episode['platform_urls'] ?? [] )
		);

		$seen = [];
		$out  = [];
		foreach ( $links as $link ) {
			$url = (string) ( $link['url'] ?? '' );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$out[]        = $link;
		}

		return $out;
	}

	/**
	 * Episode label like "01 / Episode" or "Episode 3 · Season 1".
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param bool                 $show_number Show episode number.
	 * @param bool                 $show_season Show season.
	 * @return string
	 */
	public function episode_label( array $episode, bool $show_number = true, bool $show_season = false ): string {
		$number = (string) ( $episode['episode_number'] ?? '' );
		$season = (string) ( $episode['season_number'] ?? '' );

		if ( $show_number && '' !== $number ) {
			$label = sprintf( '%02d', (int) $number ) . ' / ' . __( 'Episode', 'elementor-podcast-manager' );
		} else {
			$label = __( 'Episode', 'elementor-podcast-manager' );
		}

		if ( $show_season && '' !== $season ) {
			$label .= ' · ' . sprintf( __( 'Season %s', 'elementor-podcast-manager' ), $season );
		}

		return esc_html( $label );
	}

	/**
	 * Artwork URL helper.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param string               $size Image size.
	 * @return string
	 */
	public function artwork_url( array $episode, string $size = 'medium' ): string {
		$id = (int) ( $episode['artwork_id'] ?? 0 );

		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, $size );

		return $url ? (string) $url : '';
	}

	/**
	 * Play button for cards and rows. Carries everything the player engine
	 * needs to create the episode's controller lazily on first click.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param string               $class   Button class.
	 * @return string
	 */
	public function list_play_button( array $episode, string $class ): string {
		$title = (string) ( $episode['title'] ?? '' );

		return '<button type="button" class="' . esc_attr( $class ) . '"'
			. ' data-epm-card-play="' . esc_attr( (string) $episode['id'] ) . '"'
			. ' data-epm-src="' . esc_url( (string) $episode['audio_url'] ) . '"'
			. ' data-epm-title="' . esc_attr( $title ) . '"'
			. ' data-epm-artwork="' . esc_url( $this->artwork_url( $episode, 'medium' ) ) . '"'
			. ' data-epm-duration="' . esc_attr( (string) (int) ( $episode['duration_seconds'] ?? 0 ) ) . '"'
			. ' aria-pressed="false"'
			/* translators: %s: episode title */
			. ' aria-label="' . esc_attr( sprintf( __( 'Play %s', 'elementor-podcast-manager' ), $title ) ) . '">'
			. $this->play_icon( 'play' )
			. '<span>' . esc_html__( 'Play', 'elementor-podcast-manager' ) . '</span></button>';
	}

	/**
	 * Episode card (grid/cards layouts).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options.
	 * @return string
	 */
	public function episode_card( array $episode, array $args = [] ): string {
		$args = wp_parse_args(
			$args,
			[
				'show_artwork'      => true,
				'show_title'        => true,
				'show_excerpt'      => true,
				'show_episode_number' => true,
				'show_guest'        => true,
				'show_date'         => true,
				'show_duration'     => true,
				'show_play_button'  => true,
				'excerpt_length'    => 20,
				'style_vars'        => [],
				'link'              => true,
			]
		);

		$out = '<article class="epm-episode-card"' . $this->style_vars( (array) $args['style_vars'] ) . '>';

		if ( $args['show_artwork'] ) {
			$art = $this->artwork( $episode, 'medium', 'epm-episode-card__artwork-img' );
			if ( '' !== $art ) {
				$out .= '<div class="epm-episode-card__artwork">';
				if ( $args['link'] ) {
					$out .= '<a href="' . esc_url( (string) $episode['url'] ) . '" tabindex="-1" aria-hidden="true">' . $art . '</a>';
				} else {
					$out .= $art;
				}
				$out .= '</div>';
			}
		}

		$out .= '<div class="epm-episode-card__body">';

		if ( $args['show_episode_number'] ) {
			$label = $this->episode_label( $episode, true, false );
			if ( '' !== $label ) {
				$out .= '<p class="epm-episode-card__label">' . $label . '</p>';
			}
		}

		if ( $args['show_guest'] && '' !== (string) ( $episode['guest_name'] ?? '' ) ) {
			$out .= '<p class="epm-episode-card__guest">' . esc_html( (string) $episode['guest_name'] ) . '</p>';
		}

		if ( $args['show_title'] ) {
			$title = esc_html( (string) $episode['title'] );
			$out  .= '<h3 class="epm-episode-card__title">';
			$out  .= $args['link'] ? '<a href="' . esc_url( (string) $episode['url'] ) . '">' . $title . '</a>' : $title;
			$out  .= '</h3>';
		}

		if ( $args['show_excerpt'] ) {
			$excerpt = '' !== (string) ( $episode['short_description'] ?? '' )
				? (string) $episode['short_description']
				: wp_trim_words( wp_strip_all_tags( (string) ( $episode['description'] ?? '' ) ), (int) $args['excerpt_length'] );
			if ( '' !== $excerpt ) {
				$out .= '<p class="epm-episode-card__excerpt">' . esc_html( $excerpt ) . '</p>';
			}
		}

		$meta_fields = [];
		if ( $args['show_date'] ) {
			$meta_fields[] = 'date';
		}
		if ( $args['show_duration'] ) {
			$meta_fields[] = 'duration';
		}
		if ( ! empty( $meta_fields ) ) {
			$out .= $this->metadata( $episode, $meta_fields );
		}

		if ( $args['show_play_button'] && ! empty( $episode['has_audio'] ) ) {
			$out .= $this->list_play_button( $episode, 'epm-episode-card__play' );
		}

		$out .= '</div></article>';

		return $out;
	}

	/**
	 * Episode row (list / editorial-rows layouts).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options.
	 * @return string
	 */
	public function episode_row( array $episode, array $args = [] ): string {
		$args = wp_parse_args(
			$args,
			[
				'show_artwork'      => false,
				'show_title'        => true,
				'show_excerpt'      => false,
				'show_episode_number' => true,
				'show_guest'        => true,
				'show_date'         => true,
				'show_duration'     => true,
				'show_play_button'  => true,
				'excerpt_length'    => 20,
				'style_vars'        => [],
			]
		);

		$number = (string) ( $episode['episode_number'] ?? '' );

		$out = '<article class="epm-episode-row"' . $this->style_vars( (array) $args['style_vars'] ) . '>';

		if ( $args['show_episode_number'] && '' !== $number ) {
			$out .= '<span class="epm-episode-row__number" aria-hidden="true">' . esc_html( sprintf( '%02d', (int) $number ) ) . '</span>';
		}

		$out .= '<div class="epm-episode-row__content">';

		if ( $args['show_guest'] && '' !== (string) ( $episode['guest_name'] ?? '' ) ) {
			$out .= '<p class="epm-episode-row__guest">' . esc_html( (string) $episode['guest_name'] ) . '</p>';
		}

		if ( $args['show_title'] ) {
			$out .= '<h3 class="epm-episode-row__title"><a href="' . esc_url( (string) $episode['url'] ) . '">' . esc_html( (string) $episode['title'] ) . '</a></h3>';
		}

		if ( $args['show_excerpt'] ) {
			$excerpt = '' !== (string) ( $episode['short_description'] ?? '' )
				? (string) $episode['short_description']
				: wp_trim_words( wp_strip_all_tags( (string) ( $episode['description'] ?? '' ) ), (int) $args['excerpt_length'] );
			if ( '' !== $excerpt ) {
				$out .= '<p class="epm-episode-row__excerpt">' . esc_html( $excerpt ) . '</p>';
			}
		}

		$out .= '</div>';

		$out .= '<div class="epm-episode-row__aside">';

		$meta_fields = [];
		if ( $args['show_date'] ) {
			$meta_fields[] = 'date';
		}
		if ( $args['show_duration'] ) {
			$meta_fields[] = 'duration';
		}
		if ( ! empty( $meta_fields ) ) {
			$out .= $this->metadata( $episode, $meta_fields );
		}

		if ( $args['show_play_button'] && ! empty( $episode['has_audio'] ) ) {
			$out .= $this->list_play_button( $episode, 'epm-episode-row__play' );
		}

		$out .= '</div></article>';

		return $out;
	}

	/**
	 * Episode list wrapper — renders rows or cards per layout.
	 *
	 * @param \WP_Post[]           $posts Episode posts.
	 * @param array<string, mixed> $args Options: layout + card/row args.
	 * @return string
	 */
	public function episode_list( array $posts, array $args = [] ): string {
		$args = wp_parse_args(
			$args,
			[
				'layout'     => epm()->design->get( 'default_episode_layout' ),
				'style_vars' => [],
			]
		);

		$layout = sanitize_key( $args['layout'] );
		$valid  = [ 'list', 'editorial-rows', 'cards', 'grid', 'minimal' ];
		if ( ! in_array( $layout, $valid, true ) ) {
			$layout = 'list';
		}

		Assets::mark_player_used();

		if ( empty( $posts ) ) {
			$message = isset( $args['empty_message'] )
				? (string) $args['empty_message']
				: __( 'No episodes published yet.', 'elementor-podcast-manager' );

			return '' === $message ? '' : '<p class="epm-episode-list__empty">' . esc_html( $message ) . '</p>';
		}

		// Prime attachment caches once for the whole list (F18).
		Episodes::prime_attachments( $posts );

		$out = '<div class="epm-episode-list epm-episode-list--' . esc_attr( $layout ) . '"' . $this->style_vars( (array) $args['style_vars'] ) . '>';

		foreach ( $posts as $post ) {
			$episode = epm()->episodes->get_data( $post );

			if ( ! $episode ) {
				continue;
			}

			if ( in_array( $layout, [ 'cards', 'grid' ], true ) ) {
				$out .= $this->episode_card( $episode, $args );
			} else {
				$out .= $this->episode_row( $episode, $args );
			}
		}

		$out .= '</div>';

		return $out;
	}
}
