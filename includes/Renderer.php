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
	 * Taxonomy of episode topics (registered by EpisodePostType when
	 * available; every topic feature checks taxonomy_exists() first).
	 */
	public const TOPIC_TAXONOMY = 'podcast_topic';

	/**
	 * Seconds from a timestamp link value (the "t" query argument).
	 *
	 * Accepts plain seconds ("83"), h/m/s ("1m23s", "1h2m3s", "45s") and
	 * clock notation ("1:23", "1:02:03"). Anything else is 0.
	 *
	 * @param string $value Raw value.
	 * @return int Seconds, never negative.
	 */
	public static function parse_timestamp( string $value ): int {
		$value = strtolower( trim( $value ) );

		if ( '' === $value || strlen( $value ) > 16 ) {
			return 0;
		}

		if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return (int) floor( (float) $value );
		}

		if ( preg_match( '/^(\d+):([0-5]?\d)(?::([0-5]?\d))?$/', $value, $m ) ) {
			return isset( $m[3] )
				? (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]
				: (int) $m[1] * 60 + (int) $m[2];
		}

		if ( preg_match( '/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $m ) ) {
			return (int) ( $m[1] ?? 0 ) * 3600 + (int) ( $m[2] ?? 0 ) * 60 + (int) ( $m[3] ?? 0 );
		}

		return 0;
	}

	/**
	 * Timestamp link value for a position: "45s", "1m23s", "1h2m3s".
	 *
	 * @param int $seconds Position in seconds.
	 * @return string
	 */
	public static function timestamp_param( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$h       = intdiv( $seconds, 3600 );
		$m       = intdiv( $seconds % 3600, 60 );
		$s       = $seconds % 60;

		$out = '';
		if ( $h > 0 ) {
			$out .= $h . 'h';
		}
		if ( $m > 0 || $h > 0 ) {
			$out .= $m . 'm';
		}

		return $out . $s . 's';
	}

	/**
	 * Episode URL that starts playback at a position (?t=1m23s).
	 *
	 * The episode page's player seeks there once the audio is ready; it
	 * never starts playing on its own.
	 *
	 * @param string $url     Episode URL.
	 * @param int    $seconds Position in seconds.
	 * @return string Raw URL (escape on output).
	 */
	public static function timestamp_url( string $url, int $seconds ): string {
		if ( $seconds <= 0 ) {
			return $url;
		}

		return add_query_arg( 't', self::timestamp_param( $seconds ), $url );
	}

	/**
	 * Whether the episode is the one this request is about (its own page or
	 * its embed). Players of that episode apply a ?t= start position.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return bool
	 */
	public function is_page_episode( array $episode ): bool {
		$id = (int) ( $episode['id'] ?? 0 );

		return $id > 0
			&& is_singular( EpisodePostType::CPT )
			&& (int) get_queried_object_id() === $id;
	}

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
		$id  = (int) ( $episode['artwork_id'] ?? 0 );
		$alt = sprintf(
			/* translators: %s: episode title */
			__( 'Artwork for %s', 'elementor-podcast-manager' ),
			wp_strip_all_tags( (string) ( $episode['title'] ?? '' ) )
		);

		// Episode image hosted elsewhere (imported from a podcast host).
		$remote = (string) ( $episode['artwork_url'] ?? '' );
		if ( '' !== $remote ) {
			return '<img class="' . esc_attr( trim( 'epm-artwork ' . $class ) ) . '" src="' . esc_url( $remote ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" />';
		}

		if ( $id <= 0 ) {
			return '';
		}

		$img = wp_get_attachment_image(
			$id,
			$size,
			false,
			[
				'class'   => trim( 'epm-artwork ' . $class ),
				'alt'     => $alt,
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

	/** Contain wide imported blocks without changing stored content or table semantics. */
	public function rich_content( string $content ): string {
		$html = wp_kses_post( wpautop( $content ) );
		if ( preg_match( '/<(?:table|pre)\b/i', $html ) ) {
			return '<div class="epm-rich-scroll" role="region" tabindex="0" aria-label="' . esc_attr__( 'Scrollable episode content', 'elementor-podcast-manager' ) . '">' . $html . '</div>';
		}
		return $html;
	}

	/**
	 * Guest block: portrait, name, role and company on one line, bio.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options: show_image, show_role,
	 *   show_company, show_bio, heading ('' = none; the episode page passes
	 *   "Guest"), heading_tag (h2|h3|h4).
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
				'heading'      => '',
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
		$details = [];

		if ( $args['show_role'] && '' !== $role ) {
			$details[] = '<span class="epm-guest__role">' . esc_html( $role ) . '</span>';
		}
		if ( $args['show_company'] && '' !== $company ) {
			$details[] = '<span class="epm-guest__company">' . esc_html( $company ) . '</span>';
		}
		if ( ! empty( $details ) ) {
			$out .= '<p class="epm-guest__details">' . implode( '<span class="epm-guest__sep" aria-hidden="true"> · </span>', $details ) . '</p>';
		}
		if ( $args['show_bio'] && '' !== (string) ( $episode['guest_bio'] ?? '' ) ) {
			$out .= '<div class="epm-guest__bio">' . $this->rich_content( (string) $episode['guest_bio'] ) . '</div>';
		}

		$out .= '</div></div>';

		if ( '' !== (string) $args['heading'] ) {
			$tag = $this->heading_tag( $args );
			$out = '<div class="epm-guest-block"><' . $tag . ' class="epm-guest__heading">' . esc_html( (string) $args['heading'] ) . '</' . $tag . '>' . $out . '</div>';
		}

		return $out;
	}

	/**
	 * Section heading element for show notes, chapters and transcripts.
	 *
	 * The level depends on where the section sits in the page outline, so
	 * callers pass it ('h2' on the automatic episode page, where the theme
	 * prints the episode title as H1). Anything else falls back to h3.
	 *
	 * @param array<string, mixed> $args Renderer args with optional heading_tag.
	 * @return string h2|h3|h4.
	 */
	public function heading_tag( array $args ): string {
		$tag = strtolower( (string) ( $args['heading_tag'] ?? 'h3' ) );

		return in_array( $tag, [ 'h2', 'h3', 'h4' ], true ) ? $tag : 'h3';
	}

	/**
	 * Chapters list. Clicking a chapter seeks the nearest player.
	 *
	 * The whole row (timestamp and title) is one seek button, so the part
	 * people read is also the part they can tap.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options: heading, heading_tag (h2|h3|h4),
	 *                                   sticky (bool, default true: playback
	 *                                   started here brings the sticky player;
	 *                                   false inside a player).
	 * @return string
	 */
	public function chapters( array $episode, array $args = [] ): string {
		$chapters = $episode['chapters'] ?? [];

		if ( empty( $chapters ) ) {
			return '';
		}

		// A chapter list can start playback on a page without any player:
		// the sticky bar then carries pause and seek. Inside a player
		// (sticky false) the list carries no preference of its own and
		// follows the player's.
		$sticky = '';
		if ( $args['sticky'] ?? true ) {
			$sticky = ' data-epm-sticky-player="' . ( $this->request_sticky_for_lists() ? '1' : '0' ) . '"';
		}

		$heading = $args['heading'] ?? __( 'Chapters', 'elementor-podcast-manager' );
		$tag     = $this->heading_tag( $args );

		// Everything the player engine needs when playback starts here:
		// the sticky bar and the lock screen show title and artwork.
		$out = '<div class="epm-chapters" data-epm-chapters'
			. ' data-epm-episode-id="' . esc_attr( (string) ( $episode['id'] ?? 0 ) ) . '"'
			. ' data-epm-src="' . esc_url( (string) ( $episode['audio_url'] ?? '' ) ) . '"'
			. ' data-epm-title="' . esc_attr( (string) ( $episode['title'] ?? '' ) ) . '"'
			. ' data-epm-artwork="' . esc_url( $this->artwork_url( $episode, 'medium' ) ) . '"'
			. ' data-epm-duration="' . esc_attr( (string) (int) ( $episode['duration_seconds'] ?? 0 ) ) . '"'
			. $sticky . '>';
		if ( '' !== (string) $heading ) {
			$out .= '<' . $tag . ' class="epm-chapters__heading">' . esc_html( (string) $heading ) . '</' . $tag . '>';
		}
		$out .= '<ol class="epm-chapters__list">';

		foreach ( $chapters as $chapter ) {
			$out .= '<li class="epm-chapters__item">';
			$out .= '<button type="button" class="epm-chapters__seek" data-epm-seek="' . esc_attr( (string) $chapter['seconds'] ) . '">';
			$out .= '<span class="epm-chapters__time">' . esc_html( $chapter['time'] ) . '</span> ';
			$out .= '<span class="epm-chapters__title">' . esc_html( $chapter['title'] ) . '</span>';
			$out .= '</button>';
			if ( '' !== (string) ( $chapter['url'] ?? '' ) ) {
				// "Link" alone repeats for every chapter in a links list:
				// the hidden suffix names the chapter it belongs to.
				$out .= ' <a class="epm-chapters__link" href="' . esc_url( $chapter['url'] ) . '">' . esc_html__( 'Link', 'elementor-podcast-manager' )
					. '<span class="epm-sr-only">: ' . esc_html( $chapter['title'] ) . '</span></a>';
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
	 * @param array<string, mixed> $args Options: heading, heading_tag (h2|h3|h4), collapsible.
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

		$tag     = $this->heading_tag( $args );
		$content = '<div class="epm-transcript__content">' . $this->rich_content( $transcript ) . '</div>';

		if ( $args['collapsible'] ) {
			$heading = '' !== (string) $args['heading'] ? (string) $args['heading'] : __( 'Transcript', 'elementor-podcast-manager' );

			// A real heading inside the summary keeps the section reachable
			// by heading navigation while it is collapsed.
			return '<details class="epm-transcript epm-transcript--collapsible">'
				. '<summary class="epm-transcript__summary"><' . $tag . ' class="epm-transcript__heading">' . esc_html( $heading ) . '</' . $tag . '></summary>'
				. $content
				. '</details>';
		}

		$out = '<div class="epm-transcript">';
		if ( '' !== (string) $args['heading'] ) {
			$out .= '<' . $tag . ' class="epm-transcript__heading">' . esc_html( (string) $args['heading'] ) . '</' . $tag . '>';
		}
		$out .= $content . '</div>';

		return $out;
	}

	/**
	 * Render episode show notes (rich HTML, semantic preserved).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array                $args  Optional: heading, heading_tag (h2|h3|h4).
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
				'heading' => __( 'Show notes', 'elementor-podcast-manager' ),
			]
		);

		$tag = $this->heading_tag( $args );

		$out = '<div class="epm-show-notes">';
		if ( '' !== (string) $args['heading'] ) {
			$out .= '<' . $tag . ' class="epm-show-notes__heading">' . esc_html( (string) $args['heading'] ) . '</' . $tag . '>';
		}
		$out .= '<div class="epm-show-notes__content">' . $this->rich_content( $notes ) . '</div>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render the automatic latest-episode CTA button.
	 *
	 * Only renders when the CTA is enabled in Podcast settings and a
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
				'label'   => __( 'RSS feed', 'elementor-podcast-manager' ),
				'url'     => Hosting::public_feed_url(),
				'service' => 'rss',
			];
		}

		if ( empty( $links ) ) {
			return '';
		}

		$out = '<ul class="epm-subscribe epm-subscribe--' . esc_attr( $args['display'] ) . '">';

		foreach ( $links as $link ) {
			$service = sanitize_key( $link['service'] ?? 'custom' );
			if ( 'custom' === $service ) {
				$service = Directories::detect_service( (string) $link['url'] );
			}
			$known   = Directories::services()[ $service ] ?? null;
			$label   = '' !== (string) ( $link['label'] ?? '' ) ? (string) $link['label'] : ( null !== $known ? (string) $known['label'] : ucfirst( $service ) );

			// Icon-only mode shows a visible label for services without a
			// recognizable glyph: their shared fallback icon would make a row
			// of indistinguishable buttons.
			$has_glyph  = null !== $known && '' !== (string) $known['icon'] && BrandIcons::has( (string) $known['icon'] );
			$show_label = 'icon' !== $args['display'] || ! $has_glyph;

			$out .= '<li class="epm-subscribe__item epm-subscribe__item--' . esc_attr( $service ) . '">';
			$out .= '<a class="epm-subscribe__link" href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener">';

			if ( 'text' !== $args['display'] ) {
				$out .= '<span class="epm-subscribe__icon" aria-hidden="true">' . $this->service_icon( $service ) . '</span>';
			}
			if ( $show_label ) {
				$out .= '<span class="epm-subscribe__label">' . esc_html( $label ) . '</span>';
			} else {
				$out .= '<span class="epm-sr-only">' . esc_html( $label ) . '</span>';
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
		// Recognizable platform glyphs (monochrome, currentColor); neutral
		// shapes for services without one.
		$known = Directories::services()[ $service ] ?? null;
		$glyph = null !== $known && '' !== $known['icon'] ? BrandIcons::svg( (string) $known['icon'] ) : '';
		if ( '' !== $glyph ) {
			return $glyph;
		}

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
	 * Both play and pause glyphs, stacked in one grid cell.
	 *
	 * Every play toggle (full player, sticky bar, card and row buttons)
	 * uses this markup: the stylesheet cross-fades the two glyphs from the
	 * button's own .is-playing class, so the icon always matches the state.
	 *
	 * @return string
	 */
	public function play_toggle_icons(): string {
		return '<span class="epm-icon-swap" aria-hidden="true">'
			. '<span class="epm-player__icon-play">' . $this->play_icon( 'play' ) . '</span>'
			. '<span class="epm-player__icon-pause">' . $this->play_icon( 'pause' ) . '</span>'
			. '</span>';
	}

	/**
	 * Inline SVG icon (stroke, currentColor) for share and video controls.
	 *
	 * @param string $name share|check|link|clock|code|play.
	 * @return string
	 */
	public function ui_icon( string $name ): string {
		$paths = [
			'share' => '<path d="M12 4v11M8 8l4-4 4 4"/><path d="M6 12v6.5A1.5 1.5 0 0 0 7.5 20h9a1.5 1.5 0 0 0 1.5-1.5V12"/>',
			'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'link'  => '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
			'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/>',
			'code'  => '<path d="M9 8l-4 4 4 4M15 8l4 4-4 4"/>',
		];

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Share menu for an episode: copy link, copy link at the current
	 * position (?t=), the device share sheet and the embed code.
	 *
	 * A menu button (APG pattern): the menu opens below the button, arrow
	 * keys move between items, Escape closes it and returns focus. Labels are
	 * rendered here so every string stays translatable; the player engine
	 * fills in the position and shows "Share…" only where the browser offers
	 * a share sheet.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param string               $id      Unique ID prefix (the player ID).
	 * @return string Empty when the episode has no public URL.
	 */
	public function share_menu( array $episode, string $id ): string {
		$url = (string) ( $episode['url'] ?? '' );

		if ( '' === $url || ! Episodes::is_publicly_visible( (int) ( $episode['id'] ?? 0 ) ) ) {
			return '';
		}

		$menu_id = $id . '-share';
		$title   = wp_strip_all_tags( (string) ( $episode['title'] ?? '' ) );
		$embed   = Embed::code( $episode );

		$item = function ( string $action, string $icon, string $label, bool $hidden = false, string $extra = '' ): string {
			return '<button type="button" role="menuitem" tabindex="-1" class="epm-share__item" data-epm-share-action="' . esc_attr( $action ) . '"' . ( $hidden ? ' hidden' : '' ) . '>'
				. $this->ui_icon( $icon )
				. '<span class="epm-share__item-label"' . $extra . '>' . esc_html( $label ) . '</span>'
				. '</button>';
		};

		/* translators: %s: playback position, e.g. 12:34 */
		$at_template = __( 'Copy link at %s', 'elementor-podcast-manager' );

		$out  = '<div class="epm-share" data-epm-share data-epm-url="' . esc_url( $url ) . '" data-epm-title="' . esc_attr( $title ) . '"'
			. ( '' !== $embed ? ' data-epm-embed-code="' . esc_attr( $embed ) . '"' : '' ) . '>';
		$out .= '<button type="button" class="epm-share__toggle" data-epm-share-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="' . esc_attr( $menu_id ) . '">'
			. '<span class="epm-icon-swap" aria-hidden="true">'
			. '<span class="epm-share__icon-idle">' . $this->ui_icon( 'share' ) . '</span>'
			. '<span class="epm-share__icon-done">' . $this->ui_icon( 'check' ) . '</span>'
			. '</span>'
			// Both labels share one grid cell, so the button never changes width.
			. '<span class="epm-share__label">'
			. '<span class="epm-share__label-idle">' . esc_html__( 'Share', 'elementor-podcast-manager' ) . '</span>'
			. '<span class="epm-share__label-done" aria-hidden="true">' . esc_html__( 'Copied', 'elementor-podcast-manager' ) . '</span>'
			. '</span>'
			. '</button>';

		$out .= '<div class="epm-share__menu" id="' . esc_attr( $menu_id ) . '" role="menu" aria-label="' . esc_attr__( 'Share this episode', 'elementor-podcast-manager' ) . '" data-epm-share-menu hidden>';
		$out .= $item( 'copy', 'link', __( 'Copy link', 'elementor-podcast-manager' ) );
		// Shown once the episode has a position to share.
		$out .= $item( 'copy-time', 'clock', sprintf( $at_template, '0:00' ), true, ' data-epm-share-time-label data-template="' . esc_attr( $at_template ) . '"' );
		// The device share sheet, where the browser offers one.
		$out .= $item( 'native', 'share', __( 'Share…', 'elementor-podcast-manager' ), true );
		if ( '' !== $embed ) {
			$out .= $item( 'embed', 'code', __( 'Copy embed code', 'elementor-podcast-manager' ) );
		}
		$out .= '</div>';

		// When the browser blocks copying, the text is offered here instead.
		$out .= '<div class="epm-share__manual" data-epm-share-manual hidden>'
			. '<label class="epm-share__manual-label" for="' . esc_attr( $menu_id ) . '-manual">' . esc_html__( 'Your browser blocked copying. Select the text and copy it.', 'elementor-podcast-manager' ) . '</label>'
			. '<textarea class="epm-share__manual-field" id="' . esc_attr( $menu_id ) . '-manual" rows="3" readonly data-epm-share-manual-field></textarea>'
			. '</div>';

		$out .= '</div>';

		return $out;
	}

	/**
	 * Parse a video URL into a click-to-load source.
	 *
	 * YouTube (watch, youtu.be, embed, shorts and live URLs) plays through
	 * youtube-nocookie.com, Vimeo with do-not-track, and direct video files
	 * in a native <video>. Other addresses become a plain link.
	 *
	 * @param string $url Video URL.
	 * @return array{kind: string, id: string, src: string, host: string}|null
	 */
	public static function video_source( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$host = strtolower( (string) preg_replace( '/^(www|m|music)\./', '', strtolower( (string) $parts['host'] ) ) );
		$path = (string) ( $parts['path'] ?? '' );

		$youtube = '';
		if ( in_array( $host, [ 'youtube.com', 'youtube-nocookie.com' ], true ) ) {
			if ( preg_match( '#^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{6,20})#', $path, $m ) ) {
				$youtube = $m[1];
			} else {
				wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
				$youtube = (string) ( $query['v'] ?? '' );
			}
		} elseif ( 'youtu.be' === $host ) {
			$youtube = trim( $path, '/' );
		}

		if ( '' !== $youtube ) {
			return preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $youtube )
				? [ 'kind' => 'youtube', 'id' => $youtube, 'src' => '', 'host' => 'YouTube' ]
				: null;
		}

		if ( in_array( $host, [ 'vimeo.com', 'player.vimeo.com' ], true ) && preg_match( '#/(?:video/)?(\d{5,12})(?:/|$)#', $path, $m ) ) {
			$source = [ 'kind' => 'vimeo', 'id' => $m[1], 'src' => '', 'host' => 'Vimeo' ];
			wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
			$hash = $query['h'] ?? basename( rtrim( $path, '/' ) );
			if ( is_string( $hash ) && $hash !== $m[1] && preg_match( '/^[a-zA-Z0-9]{6,64}$/', $hash ) ) {
				$source['hash'] = $hash;
			}
			return $source;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, [ 'mp4', 'm4v', 'webm', 'mov', 'ogv' ], true ) ) {
			$file_host = strtolower( (string) $parts['host'] );
			$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			return [ 'kind' => 'file', 'id' => '', 'src' => esc_url_raw( $url ), 'host' => $file_host === $site_host ? '' : $file_host ];
		}

		return [ 'kind' => 'link', 'id' => '', 'src' => esc_url_raw( $url ), 'host' => '' ];
	}

	/**
	 * Episode video as a privacy-friendly click-to-load facade.
	 *
	 * Nothing is requested from the video platform until the visitor
	 * presses play: the facade shows the episode artwork and a play button;
	 * the player engine then swaps in youtube-nocookie.com / Vimeo (do not
	 * track) or a native <video> and moves focus into it.
	 *
	 * @param array<string, mixed> $episode Episode data (youtube_url, video_url).
	 * @param array<string, mixed> $args    Options: show_note (bool, default true).
	 * @return string Empty when the episode has no video.
	 */
	public function video( array $episode, array $args = [] ): string {
		$args = wp_parse_args( $args, [ 'show_note' => true ] );

		$source = null;
		foreach ( [ 'youtube_url', 'video_url' ] as $key ) {
			$source = self::video_source( (string) ( $episode[ $key ] ?? '' ) );
			if ( null !== $source ) {
				break;
			}
		}

		if ( null === $source ) {
			return '';
		}

		$title = wp_strip_all_tags( (string) ( $episode['title'] ?? '' ) );

		if ( 'link' === $source['kind'] ) {
			Assets::enqueue_style();

			return '<p class="epm-video epm-video--link"><a class="epm-video__link" href="' . esc_url( $source['src'] ) . '">'
				/* translators: %s: episode title */
				. esc_html( sprintf( __( 'Watch the video of %s', 'elementor-podcast-manager' ), $title ) )
				. '</a></p>';
		}

		Assets::mark_player_used();

		/* translators: %s: episode title */
		$frame_title = sprintf( __( 'Video: %s', 'elementor-podcast-manager' ), $title );
		$poster      = $this->artwork_url( $episode, 'large' );

		$out = '<figure class="epm-video" data-epm-video'
			. ' data-epm-video-kind="' . esc_attr( $source['kind'] ) . '"'
			. ( '' !== $source['id'] ? ' data-epm-video-id="' . esc_attr( $source['id'] ) . '"' : '' )
			. ( '' !== $source['src'] ? ' data-epm-video-src="' . esc_url( $source['src'] ) . '"' : '' )
			. ( isset( $source['hash'] ) ? ' data-epm-video-hash="' . esc_attr( $source['hash'] ) . '"' : '' )
			. ' data-epm-video-title="' . esc_attr( $frame_title ) . '">';

		$out .= '<div class="epm-video__frame">';
		/* translators: %s: episode title */
		$out .= '<button type="button" class="epm-video__facade" data-epm-video-play aria-label="' . esc_attr( sprintf( __( 'Play video: %s', 'elementor-podcast-manager' ), $title ) ) . '">';
		if ( '' !== $poster ) {
			// The same image twice: a soft backdrop that fills the 16:9 frame
			// and the square artwork itself, uncropped.
			$out .= '<img class="epm-video__backdrop" src="' . esc_url( $poster ) . '" alt="" loading="lazy" decoding="async" />';
			$out .= '<img class="epm-video__poster" src="' . esc_url( $poster ) . '" alt="" loading="lazy" decoding="async" />';
		}
		$out .= '<span class="epm-video__button" aria-hidden="true">' . $this->play_icon( 'play' ) . '</span>';
		$out .= '</button>';
		$out .= '</div>';

		if ( $args['show_note'] && '' !== $source['host'] ) {
			$out .= '<figcaption class="epm-video__note">'
				/* translators: %s: video platform, e.g. YouTube */
				. esc_html( sprintf( __( 'The video loads from %s when you play it.', 'elementor-podcast-manager' ), $source['host'] ) )
				. '</figcaption>';
		}

		$out .= '<div class="epm-video__error" data-epm-video-error role="alert" hidden><p>'
			. esc_html__( 'The video could not be loaded. Try again or open the original video.', 'elementor-podcast-manager' )
			. '</p><button type="button" class="epm-video__retry" data-epm-video-retry>' . esc_html__( 'Try again', 'elementor-podcast-manager' ) . '</button></div>';
		$out .= '<p class="epm-video__fallback"><a class="epm-video__link" href="' . esc_url( (string) $episode[ $key ] ) . '">'
			. esc_html__( 'Open original video', 'elementor-podcast-manager' ) . '</a></p>';
		$out .= '</figure>';

		return $out;
	}

	/**
	 * Topic chips of an episode (links to the topic archives).
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return string Empty when topics are unavailable or none are set.
	 */
	public function topics( array $episode ): string {
		if ( ! taxonomy_exists( self::TOPIC_TAXONOMY ) ) {
			return '';
		}

		$terms = get_the_terms( (int) ( $episode['id'] ?? 0 ), self::TOPIC_TAXONOMY );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$out = '<ul class="epm-topics" aria-label="' . esc_attr__( 'Topics', 'elementor-podcast-manager' ) . '">';
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			$name = esc_html( $term->name );
			$out .= '<li class="epm-topics__item">'
				. ( is_string( $link ) ? '<a class="epm-topic" href="' . esc_url( $link ) . '">' . $name . '</a>' : '<span class="epm-topic">' . $name . '</span>' )
				. '</li>';
		}
		$out .= '</ul>';

		return $out;
	}

	/**
	 * Topic slugs from a shortcode or widget value ("a,b" or an array).
	 *
	 * @param mixed $value Raw value.
	 * @return string[] Sanitized slugs.
	 */
	public static function topic_slugs( $value ): array {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );

		return array_values( array_unique( array_filter( array_map( 'sanitize_title', array_map( 'strval', $list ) ) ) ) );
	}

	/**
	 * Episode query arguments that limit a list to topics. Empty when the
	 * topics taxonomy is not registered or no slug is given.
	 *
	 * @param string[] $slugs Topic slugs.
	 * @return array<string, mixed>
	 */
	public static function topic_query_args( array $slugs ): array {
		if ( empty( $slugs ) || ! taxonomy_exists( self::TOPIC_TAXONOMY ) ) {
			return [];
		}

		return [
			'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => self::TOPIC_TAXONOMY,
					'field'    => 'slug',
					'terms'    => $slugs,
				],
			],
		];
	}

	/**
	 * THE player. One engine, layouts are configurations.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param array<string, mixed> $args Options:
	 *   layout, show_* flags (show_share: the share menu in the secondary
	 *   row), style_vars, sticky (bool), player_id, label (replaces the
	 *   episode label), title_url (links the title, opening in the top
	 *   window), class (extra classes).
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
				'show_share'           => true,
				'sticky'               => false,
				'style_vars'           => [],
				'player_id'            => 'epm-player-' . (int) ( $episode['id'] ?? 0 ) . '-' . wp_unique_id(),
				'label'                => '',
				'title_url'            => '',
				'title_new_window'     => false,
				'class'                => '',
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
		foreach ( preg_split( '/\s+/', (string) $args['class'] ) as $extra ) {
			$extra = sanitize_html_class( $extra );
			if ( '' !== $extra ) {
				$classes[] = $extra;
			}
		}
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
			// Whether playback started here opens the sticky bar (the bar's
			// shell may be on the page for a list even when this is off).
			. ' data-epm-sticky-player="' . ( $args['sticky'] ? '1' : '0' ) . '"'
			// The episode this page is about: a ?t= link starts it there.
			. ( $this->is_page_episode( $episode ) ? ' data-epm-page-episode' : '' )
			. $this->style_vars( (array) $args['style_vars'] )
			. '>';

		// Hidden native audio element — the single engine underneath.
		// Audio on another host (a hosting service, a tracking prefix)
		// loads on the first press only: no request carries the visitor's
		// address there before they ask for it, and page views never count
		// as downloads. The duration comes from data-epm-duration.
		$audio_host = strtolower( (string) wp_parse_url( (string) $episode['audio_url'], PHP_URL_HOST ) );
		$preload    = ( '' === $audio_host || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $audio_host ) ? 'metadata' : 'none';

		/**
		 * Preload behaviour of a player's audio element.
		 *
		 * @param string $preload 'metadata' for audio on this site, 'none' for audio on another host.
		 * @param array  $episode Episode data.
		 */
		$preload = (string) apply_filters( 'epm_player_preload', $preload, $episode );
		if ( ! in_array( $preload, [ 'none', 'metadata', 'auto' ], true ) ) {
			$preload = 'none';
		}
		$out .= '<audio preload="' . esc_attr( $preload ) . '" src="' . esc_url( (string) $episode['audio_url'] ) . '"></audio>';

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
			$label = '' !== (string) $args['label']
				? esc_html( (string) $args['label'] )
				: $this->episode_label( $episode, (bool) $args['show_episode_number'], (bool) $args['show_season'] );
			if ( '' !== $label ) {
				$out .= '<p class="epm-player__label">' . $label . '</p>';
			}
		}
		if ( $args['show_title'] ) {
			$title = esc_html( (string) ( $episode['title'] ?? '' ) );
			if ( '' !== (string) $args['title_url'] ) {
				$title = '<a class="epm-player__title-link" href="' . esc_url( (string) $args['title_url'] ) . '"'
					. ( $args['title_new_window'] ? ' target="_blank" rel="noopener"' : ' target="_top"' ) . '>' . $title . '</a>';
			}
			$out .= '<p class="epm-player__title">' . $title . '</p>';
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

		// Controls row: the transport group (play, back, forward) stays
		// together; the timeline follows as its own group.
		$out .= '<div class="epm-player__controls">';
		$out .= '<div class="epm-player__transport">';

		$out .= '<button type="button" class="epm-player__play" data-epm-play aria-label="' . esc_attr__( 'Play episode', 'elementor-podcast-manager' ) . '"'
			. ' data-label-play="' . esc_attr__( 'Play episode', 'elementor-podcast-manager' ) . '"'
			. ' data-label-pause="' . esc_attr__( 'Pause episode', 'elementor-podcast-manager' ) . '">'
			. $this->play_toggle_icons()
			. '</button>';

		if ( $args['show_skip_backward'] ) {
			$out .= '<button type="button" class="epm-player__skip" data-epm-seek-rel="-15" aria-label="' . esc_attr__( 'Skip back 15 seconds', 'elementor-podcast-manager' ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M11 8l-4 4 4 4M18 8l-4 4 4 4"/></svg>'
				. '<span aria-hidden="true">15</span></button>';
		}

		if ( $args['show_skip_forward'] ) {
			$out .= '<button type="button" class="epm-player__skip" data-epm-seek-rel="30" aria-label="' . esc_attr__( 'Skip forward 30 seconds', 'elementor-podcast-manager' ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M13 8l4 4-4 4M6 8l4 4-4 4"/></svg>'
				. '<span aria-hidden="true">30</span></button>';
		}

		$out .= '</div>'; // .epm-player__transport

		// Timeline.
		$out .= '<div class="epm-player__timeline-wrap">';
		$out .= '<div class="epm-player__timeline" data-epm-timeline role="slider" tabindex="0"'
			. ' aria-label="' . esc_attr__( 'Seek', 'elementor-podcast-manager' ) . '"'
			. ' aria-valuemin="0" aria-valuemax="' . esc_attr( (string) $duration_seconds ) . '" aria-valuenow="0">';
		$out .= '<div class="epm-player__track"><div class="epm-player__progress" data-epm-progress></div></div>';
		$out .= '<div class="epm-player__handle" data-epm-handle></div>';
		$out .= '</div>';
		$out .= '<div class="epm-player__times"><span data-epm-current>0:00</span><span data-epm-total>' . esc_html( Episodes::format_duration( $duration_seconds ) ) . '</span></div>';
		$out .= '</div>'; // .epm-player__timeline-wrap

		$out .= '</div>'; // .epm-player__controls

		// Secondary row: speed, volume, download.
		$secondary = '';
		if ( $args['show_playback_speed'] ) {
			// The visible value stays part of the accessible name, so speech
			// users can say what they see ("1×").
			$secondary .= '<button type="button" class="epm-player__speed" data-epm-speed>'
				. '<span class="epm-sr-only">' . esc_html__( 'Playback speed', 'elementor-podcast-manager' ) . ' </span>'
				. '<span data-epm-speed-value>1×</span></button>';
		}
		if ( $args['show_volume'] ) {
			// Named once: a hidden label around it as well made screen
			// readers read "Volume" as text and again as the slider's name.
			$secondary .= '<span class="epm-player__volume">'
				. '<input type="range" min="0" max="1" step="0.05" value="1" data-epm-volume aria-label="' . esc_attr__( 'Volume', 'elementor-podcast-manager' ) . '" /></span>';
		}
		if ( $args['show_download'] && ! empty( $episode['audio_url'] ) ) {
			$secondary .= '<a class="epm-player__download" href="' . esc_url( (string) $episode['audio_url'] ) . '" download aria-label="' . esc_attr__( 'Download episode', 'elementor-podcast-manager' ) . '">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 4v12m0 0l-4-4m4 4l4-4M5 20h14"/></svg></a>';
		}
		if ( $args['show_share'] ) {
			// Sits at the trailing end of the row (see the stylesheet).
			$secondary .= $this->share_menu( $episode, (string) $args['player_id'] );
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
				// The player has its own controls: its sticky setting decides.
				. $this->chapters( $episode, [ 'heading' => '', 'sticky' => false ] )
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

		if ( 'full' === $layout ) { $out = '<div class="epm-player-frame">' . $out . '</div>'; }
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
	 * Episode label ("eyebrow") like "01 / Episode", "01 / Episode · Season 1"
	 * or "Bonus".
	 *
	 * Only says something the title does not: the number (when shown), the
	 * season (when shown) and a bonus or trailer type. Without any of them
	 * it is empty, so no bare "Episode" eyebrow repeats on every item.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param bool                 $show_number Show episode number.
	 * @param bool                 $show_season Show season.
	 * @return string Escaped label, '' when there is nothing to say.
	 */
	public function episode_label( array $episode, bool $show_number = true, bool $show_season = false ): string {
		$number = (string) ( $episode['episode_number'] ?? '' );
		$season = (string) ( $episode['season_number'] ?? '' );
		$parts  = [];

		if ( $show_number && '' !== $number ) {
			$parts[] = sprintf( '%02d', (int) $number ) . ' / ' . __( 'Episode', 'elementor-podcast-manager' );
		}

		if ( $show_season && '' !== $season ) {
			/* translators: %s: season number */
			$parts[] = sprintf( __( 'Season %s', 'elementor-podcast-manager' ), $season );
		}

		$types = [
			'trailer' => __( 'Trailer', 'elementor-podcast-manager' ),
			'bonus'   => __( 'Bonus', 'elementor-podcast-manager' ),
		];
		$type  = (string) ( $episode['episode_type'] ?? '' );
		if ( isset( $types[ $type ] ) ) {
			$parts[] = $types[ $type ];
		}

		return esc_html( implode( ' · ', $parts ) );
	}

	/**
	 * Artwork URL helper.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param string               $size Image size.
	 * @return string
	 */
	public function artwork_url( array $episode, string $size = 'medium' ): string {
		if ( '' !== (string) ( $episode['artwork_url'] ?? '' ) ) {
			return (string) $episode['artwork_url'];
		}

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

		// Once scrolled away from the list, playback keeps its controls.
		$sticky = $this->request_sticky_for_lists();

		return '<button type="button" class="' . esc_attr( $class ) . '"'
			. ' data-epm-card-play="' . esc_attr( (string) $episode['id'] ) . '"'
			. ' data-epm-sticky-player="' . ( $sticky ? '1' : '0' ) . '"'
			. ' data-epm-src="' . esc_url( (string) $episode['audio_url'] ) . '"'
			. ' data-epm-title="' . esc_attr( $title ) . '"'
			. ' data-epm-artwork="' . esc_url( $this->artwork_url( $episode, 'medium' ) ) . '"'
			. ' data-epm-duration="' . esc_attr( (string) (int) ( $episode['duration_seconds'] ?? 0 ) ) . '"'
			// The Play/Pause label is the only state signal: aria-pressed
			// on top of it would announce "Pause …, pressed".
			/* translators: %s: episode title */
			. ' aria-label="' . esc_attr( sprintf( __( 'Play %s', 'elementor-podcast-manager' ), $title ) ) . '">'
			. $this->play_toggle_icons()
			// All three words share one grid cell and the state classes
			// (.is-playing, .has-error) show one of them, so the button
			// keeps its width when the label changes.
			. '<span class="epm-list-play__label">'
			. '<span class="epm-list-play__text--play">' . esc_html__( 'Play', 'elementor-podcast-manager' ) . '</span>'
			. '<span class="epm-list-play__text--pause" aria-hidden="true">' . esc_html__( 'Pause', 'elementor-podcast-manager' ) . '</span>'
			. '<span class="epm-list-play__text--retry" aria-hidden="true">' . esc_html__( 'Retry', 'elementor-podcast-manager' ) . '</span>'
			. '</span></button>';
	}

	/**
	 * Request the sticky player for playback that starts outside a full
	 * player (card and row buttons, chapter lists). The shell stays hidden
	 * until something plays.
	 *
	 * @return bool Whether they bring it (the markup says so, so playback
	 *              started there opens the bar).
	 */
	private function request_sticky_for_lists(): bool {
		/**
		 * Whether list play buttons and chapter lists bring the sticky player.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'epm_sticky_player_for_lists', true ) ) {
			return false;
		}
		Assets::request_sticky_player();
		return true;
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
				'show_topics'       => false,
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

		if ( $args['show_topics'] ) {
			$out .= $this->topics( $episode );
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
				'show_topics'       => false,
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

		if ( $args['show_topics'] ) {
			$out .= $this->topics( $episode );
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
	 * Empty state of an episode list: says what the place is and, for a
	 * filtered list (season, category), points to all episodes.
	 *
	 * @param array<string, mixed> $args List args: empty_message (overrides,
	 *                                   '' renders nothing), filtered (bool).
	 * @return string
	 */
	public function empty_list( array $args ): string {
		if ( isset( $args['empty_message'] ) ) {
			$message = (string) $args['empty_message'];

			return '' === $message ? '' : '<p class="epm-episode-list__empty">' . esc_html( $message ) . '</p>';
		}

		$filtered = ! empty( $args['filtered'] );
		$archive  = $filtered ? get_post_type_archive_link( EpisodePostType::CPT ) : '';
		$message  = $filtered
			? __( 'No episodes in this selection yet.', 'elementor-podcast-manager' )
			: __( 'No episodes yet. New episodes will appear here.', 'elementor-podcast-manager' );

		$link = '';
		if ( is_string( $archive ) && '' !== $archive ) {
			$link = ' <a class="epm-episode-list__empty-link" href="' . esc_url( $archive ) . '">' . esc_html__( 'Browse all episodes', 'elementor-podcast-manager' ) . '</a>';
		}

		return '<p class="epm-episode-list__empty">' . esc_html( $message ) . $link . '</p>';
	}

	/**
	 * Episode list wrapper — renders rows or cards per layout.
	 *
	 * @param \WP_Post[]           $posts Episode posts.
	 * @param array<string, mixed> $args Options: layout + card/row args,
	 *                                   filtered (bool, a season/category
	 *                                   filter is active), empty_message.
	 * @return string
	 */
	public function episode_list( array $posts, array $args = [] ): string {
		Assets::mark_player_used();

		if ( empty( $posts ) ) {
			return $this->empty_list( $args );
		}

		// Prime attachment caches once for the whole list (F18).
		Episodes::prime_attachments( $posts );

		return $this->episode_list_from_data( array_filter( array_map( [ epm()->episodes, 'get_data' ], $posts ) ), $args );
	}

	/**
	 * Episode list from episode data (Episodes::get_data()), e.g. the
	 * Design screen's sample episodes. Same markup as episode_list().
	 *
	 * @param array<int, array<string, mixed>> $episodes Episode data.
	 * @param array<string, mixed>             $args     See episode_list().
	 * @return string
	 */
	public function episode_list_from_data( array $episodes, array $args = [] ): string {
		$args = wp_parse_args(
			$args,
			[
				'layout'     => epm()->design->get( 'default_episode_layout' ),
				'style_vars' => [],
			]
		);

		$layout = sanitize_key( (string) $args['layout'] );
		if ( '' === $layout ) {
			$layout = sanitize_key( (string) epm()->design->get( 'default_episode_layout' ) );
		}
		$valid = [ 'list', 'editorial-rows', 'cards', 'grid', 'minimal' ];
		if ( ! in_array( $layout, $valid, true ) ) {
			$layout = 'list';
		}

		Assets::mark_player_used();

		if ( empty( $episodes ) ) {
			return $this->empty_list( $args );
		}

		$classes = [ 'epm-episode-list', 'epm-episode-list--' . $layout ];
		// Numbered row layouts reserve the number column on every row, so
		// titles share one edge whether or not an episode has a number. A
		// list in which no episode has a number reserves nothing.
		$numbered = in_array( $layout, [ 'list', 'editorial-rows' ], true ) && ! empty( $args['show_episode_number'] ?? true );
		if ( $numbered ) {
			$numbered = [] !== array_filter(
				$episodes,
				static function ( array $episode ): bool {
					return '' !== (string) ( $episode['episode_number'] ?? '' );
				}
			);
		}
		if ( $numbered ) {
			$classes[] = 'epm-episode-list--numbered';
		}

		$out = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->style_vars( (array) $args['style_vars'] ) . '>';

		foreach ( $episodes as $episode ) {
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
