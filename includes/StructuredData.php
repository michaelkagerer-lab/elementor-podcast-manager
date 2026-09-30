<?php
/**
 * Structured data for search engines (Layer 1).
 *
 * Episode pages get schema.org PodcastEpisode JSON-LD (part of a
 * PodcastSeries) and Open Graph audio tags; the episode archive gets the
 * PodcastSeries. Only for publicly visible episodes. SEO plugins usually
 * cover titles and descriptions but not these podcast types, so they are
 * added alongside them; turn them off with the epm_structured_data filter.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StructuredData {

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_head', [ $this, 'print' ], 30 );
	}

	/**
	 * Print JSON-LD and Open Graph audio tags where they apply.
	 *
	 * @return void
	 */
	public function print(): void {
		if ( ! (bool) apply_filters( 'epm_structured_data', true ) ) {
			return;
		}

		if ( is_singular( EpisodePostType::CPT ) ) {
			$data = epm()->episodes->get_public_data( get_queried_object_id() );
			if ( null === $data ) {
				return;
			}

			self::json_ld( self::episode( $data ) );

			if ( ! empty( $data['has_audio'] ) ) {
				printf( '<meta property="og:audio" content="%s" />' . "\n", esc_url( (string) $data['audio_url'] ) );
				if ( '' !== (string) $data['audio_mime'] ) {
					printf( '<meta property="og:audio:type" content="%s" />' . "\n", esc_attr( (string) $data['audio_mime'] ) );
				}
			}
			return;
		}

		if ( is_post_type_archive( EpisodePostType::CPT ) ) {
			self::json_ld( self::series() );
		}
	}

	/**
	 * PodcastSeries node.
	 *
	 * @return array<string, mixed>
	 */
	public static function series(): array {
		$settings = epm()->settings;
		$title    = Feed::plain_text( (string) $settings->get( 'title' ) );
		$url      = (string) $settings->get( 'website_url' );

		$series = [
			'@context'   => 'https://schema.org',
			'@type'      => 'PodcastSeries',
			'name'       => '' !== $title ? $title : Feed::plain_text( (string) get_bloginfo( 'name' ) ),
			'url'        => '' !== $url ? $url : home_url( '/' ),
			'webFeed'    => Hosting::public_feed_url(),
			'inLanguage' => Feed::rss_language( (string) $settings->get( 'language' ) ),
		];

		$description = Feed::plain_text( (string) $settings->get( 'description' ) );
		if ( '' !== $description ) {
			$series['description'] = wp_html_excerpt( $description, 500, '…' );
		}

		$author = Feed::plain_text( $settings->effective_author() );
		if ( '' !== $author ) {
			$series['author'] = [
				'@type' => 'Person',
				'name'  => $author,
			];
		}

		$image = $settings->artwork_url( 'full' );
		if ( '' !== $image ) {
			$series['image'] = $image;
		}

		return (array) apply_filters( 'epm_structured_data_series', $series );
	}

	/**
	 * PodcastEpisode node.
	 *
	 * @param array<string, mixed> $data Episode data.
	 * @return array<string, mixed>
	 */
	public static function episode( array $data ): array {
		$series = self::series();
		unset( $series['@context'] );

		$episode = [
			'@context'      => 'https://schema.org',
			'@type'         => 'PodcastEpisode',
			'name'          => Feed::plain_text( (string) $data['title'] ),
			'url'           => (string) $data['url'],
			'datePublished' => (string) get_post_time( 'c', true, (int) $data['id'] ),
			'partOfSeries'  => $series,
		];

		$summary = Feed::plain_text( (string) ( '' !== (string) $data['short_description'] ? $data['short_description'] : get_the_excerpt( (int) $data['id'] ) ) );
		if ( '' !== $summary ) {
			$episode['description'] = wp_html_excerpt( $summary, 500, '…' );
		}

		if ( (int) $data['episode_number'] > 0 ) {
			$episode['episodeNumber'] = (int) $data['episode_number'];
		}
		if ( (int) $data['season_number'] > 0 ) {
			$episode['partOfSeason'] = [
				'@type'        => 'PodcastSeason',
				'seasonNumber' => (int) $data['season_number'],
			];
		}

		$seconds = (int) $data['duration_seconds'];
		if ( ! empty( $data['has_audio'] ) ) {
			$media = [
				'@type'      => 'AudioObject',
				'contentUrl' => (string) $data['audio_url'],
			];
			if ( '' !== (string) $data['audio_mime'] ) {
				$media['encodingFormat'] = (string) $data['audio_mime'];
			}
			if ( $seconds > 0 ) {
				$media['duration'] = self::iso_duration( $seconds );
			}
			$episode['associatedMedia'] = $media;
		}
		if ( $seconds > 0 ) {
			$episode['timeRequired'] = self::iso_duration( $seconds );
		}

		$image = (string) ( $data['own_artwork_url'] ?? '' );
		if ( '' === $image && (int) $data['artwork_id'] > 0 ) {
			$image = (string) wp_get_attachment_image_url( (int) $data['artwork_id'], 'full' );
		}
		if ( '' !== $image ) {
			$episode['image'] = $image;
		}

		if ( '' !== (string) $data['guest_name'] ) {
			$episode['actor'] = [
				'@type' => 'Person',
				'name'  => Feed::plain_text( (string) $data['guest_name'] ),
			];
		}

		return (array) apply_filters( 'epm_structured_data_episode', $episode, $data );
	}

	/**
	 * Seconds as an ISO 8601 duration (PT1H2M3S).
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function iso_duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$rest    = $seconds % 60;

		return 'PT' . ( $hours > 0 ? $hours . 'H' : '' ) . ( $minutes > 0 ? $minutes . 'M' : '' ) . ( $rest > 0 || 0 === $seconds ? $rest . 'S' : '' );
	}

	/**
	 * Print one JSON-LD block.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return void
	 */
	private static function json_ld( array $node ): void {
		if ( empty( $node ) ) {
			return;
		}

		$json = wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( ! is_string( $json ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML-significant characters hex-escaped.
	}
}
