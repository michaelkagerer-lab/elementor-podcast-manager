<?php
/**
 * Integration tests for the frontend components (1.3.0): timestamp links,
 * the share menu, episode embeds, the video facade, topic filters, audio
 * preloading, the sticky player for lists, row lists and the section
 * surface of dark designs.
 * Executed inside WordPress after the fixtures were seeded:
 *
 *   wp eval-file tests/integration/frontend.php
 *
 * The site is left as it was found: meta, terms and a temporarily
 * registered taxonomy are removed again at the end of each test.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Assets;
use EPM\DesignSettings;
use EPM\Embed;
use EPM\EpisodePostType;
use EPM\Episodes;
use EPM\EpisodeTemplate;
use EPM\Renderer;

require_once __DIR__ . '/lib.php';

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

$GLOBALS['epm_test_doing_it_wrong'] = [];
add_action(
	'doing_it_wrong_run',
	static function ( $function, $message ) {
		$GLOBALS['epm_test_doing_it_wrong'][] = $function . ': ' . wp_strip_all_tags( (string) $message );
	},
	10,
	2
);

/**
 * Every src/srcset/poster URL in a piece of markup points to this site.
 *
 * @param string $html Markup.
 * @return string[] Offending URLs.
 */
function epm_test_foreign_sources( string $html ): array {
	$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$bad  = [];
	if ( preg_match_all( '/\s(?:src|srcset|poster)="([^"]*)"/i', $html, $m ) ) {
		foreach ( $m[1] as $value ) {
			foreach ( preg_split( '/\s*,\s*/', $value ) as $candidate ) {
				$url  = strtok( trim( $candidate ), ' ' );
				$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
				if ( '' !== $host && $host !== $home ) {
					$bad[] = (string) $url;
				}
			}
		}
	}

	return $bad;
}

$t = new EPM_Test_Runner();

WP_CLI::log( 'Timestamp links' );

$t->test(
	'?t= values parse as seconds, h/m/s and clock notation',
	static function ( EPM_Test_Runner $t ) {
		$cases = [
			'83'       => 83,
			'83.9'     => 83,
			'1m23s'    => 83,
			'1h2m3s'   => 3723,
			'45s'      => 45,
			'2m'       => 120,
			'1h'       => 3600,
			'1H2M3S'   => 3723,
			'1:23'     => 83,
			'1:02:03'  => 3723,
			''         => 0,
			'abc'      => 0,
			'-5'       => 0,
			'1:99'     => 0,
			'1m23'     => 0,
			'99999999999999999' => 0,
		];
		foreach ( $cases as $value => $expected ) {
			$t->same( $expected, Renderer::parse_timestamp( (string) $value ), 'parse "' . $value . '"' );
		}
	}
);

$t->test(
	'timestamp links are built in h/m/s and round-trip',
	static function ( EPM_Test_Runner $t ) {
		$t->same( '45s', Renderer::timestamp_param( 45 ) );
		$t->same( '1m23s', Renderer::timestamp_param( 83 ) );
		$t->same( '1h2m3s', Renderer::timestamp_param( 3723 ) );
		$t->same( '1h0m0s', Renderer::timestamp_param( 3600 ) );
		foreach ( [ 1, 59, 60, 61, 3599, 3600, 7322 ] as $seconds ) {
			$t->same( $seconds, Renderer::parse_timestamp( Renderer::timestamp_param( $seconds ) ), 'round trip ' . $seconds );
		}
		$t->same( 'https://example.org/podcast/a/?t=1m23s', Renderer::timestamp_url( 'https://example.org/podcast/a/', 83 ) );
		$t->same( 'https://example.org/?p=5&t=45s', Renderer::timestamp_url( 'https://example.org/?p=5', 45 ) );
		$t->same( 'https://example.org/podcast/a/', Renderer::timestamp_url( 'https://example.org/podcast/a/', 0 ), 'no position, no parameter' );
	}
);

$t->test(
	'only the player of the episode the page is about takes a ?t= start',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$renderer = epm()->renderer;
		$ep1      = epm()->episodes->get_public_data( $fx['ep1'] );
		$ep2      = epm()->episodes->get_public_data( $fx['ep2'] );

		$t->assert( false === strpos( $renderer->player( $ep1 ), 'data-epm-page-episode' ), 'no marker outside an episode page' );

		epm_test_as_episode_page(
			(int) $fx['ep1'],
			static function () use ( $t, $renderer, $ep1, $ep2 ) {
				$t->assert( false !== strpos( $renderer->player( $ep1 ), 'data-epm-page-episode' ), 'marker on the page episode' );
				$t->assert( false === strpos( $renderer->player( $ep2 ), 'data-epm-page-episode' ), 'no marker on another episode' );
			}
		);
	}
);

WP_CLI::log( 'Share menu' );

$t->test(
	'the player carries an accessible share menu with link, position and embed code',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$ep1  = epm()->episodes->get_public_data( $fx['ep1'] );
		$html = epm()->renderer->player( $ep1, [ 'layout' => 'full', 'player_id' => 'epm-test-player' ] );

		$t->assert( false !== strpos( $html, 'data-epm-share ' ), 'share wrapper' );
		$t->assert( false !== strpos( $html, 'data-epm-url="' . esc_url( $ep1['url'] ) . '"' ), 'episode URL' );
		$t->assert( (bool) preg_match( '/<button type="button" class="epm-share__toggle"[^>]*aria-haspopup="menu" aria-expanded="false" aria-controls="epm-test-player-share"/', $html ), 'menu button' );
		$t->assert( (bool) preg_match( '/<div class="epm-share__menu" id="epm-test-player-share" role="menu" aria-label="[^"]+" data-epm-share-menu hidden>/', $html ), 'menu, hidden until opened' );
		$t->same( 4, substr_count( $html, 'role="menuitem" tabindex="-1"' ), 'four items with roving focus' );
		foreach ( [ 'copy', 'copy-time', 'native', 'embed' ] as $action ) {
			$t->assert( false !== strpos( $html, 'data-epm-share-action="' . $action . '"' ), $action . ' item' );
		}
		$t->assert( (bool) preg_match( '/data-template="[^"]*%s[^"]*"/', $html ), 'position label template' );
		$t->assert( (bool) preg_match( '/<label class="epm-share__manual-label" for="epm-test-player-share-manual">/', $html ), 'manual copy field is labelled' );

		$without = epm()->renderer->player( $ep1, [ 'show_share' => false ] );
		$t->assert( false === strpos( $without, 'data-epm-share' ), 'show_share=false hides it' );
		$t->assert( false === strpos( do_shortcode( '[podcast_player id="' . $fx['ep1'] . '" share="no"]' ), 'data-epm-share' ), 'shortcode share="no"' );
		$t->assert( false !== strpos( do_shortcode( '[podcast_player id="' . $fx['ep1'] . '"]' ), 'data-epm-share' ), 'shortcode default' );
	}
);

$t->test(
	'restricted episodes offer no share menu or embed code',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		foreach ( [ 'draft', 'private', 'password', 'future' ] as $key ) {
			$data = epm()->episodes->get_data( $fx[ $key ] );
			$t->same( '', epm()->renderer->share_menu( $data, 'x' ), $key . ' share menu' );
			$t->same( '', Embed::code( $data ), $key . ' embed code' );
		}
	}
);

WP_CLI::log( 'Embeds' );

$t->test(
	'embed code is an iframe for the episode embed address with a title and fixed height',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$ep1  = epm()->episodes->get_public_data( $fx['ep1'] );
		$code = Embed::code( $ep1 );

		$t->assert( 0 === strpos( $code, '<iframe src="' . esc_url( get_post_embed_url( $fx['ep1'] ) ) . '"' ), 'src is the embed URL: ' . $code );
		$t->assert( false !== strpos( get_post_embed_url( $fx['ep1'] ), '/podcast/episode-one-hello-friends/embed/' ), 'pretty embed URL' );
		$t->assert( false !== strpos( $code, 'height="' . Embed::HEIGHT . '"' ), 'height' );
		$t->assert( (bool) preg_match( '/title="Episode One: [^"]+ – Test &amp; Talk Podcast"/u', $code ), 'title names episode and podcast' );
		$t->assert( false === strpos( $code, '<script' ), 'no script' );
	}
);

$t->test(
	'oEmbed announces the card height for episodes only',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$embed = new Embed();
		$data  = $embed->response_data( [ 'height' => 338 ], get_post( $fx['ep1'] ) );
		$t->same( Embed::HEIGHT, $data['height'], 'episode' );
		$page = $embed->response_data( [ 'height' => 338 ], get_post( $fx['shortcodes_page'] ) );
		$t->same( 338, $page['height'], 'other post types untouched' );
	}
);

$t->test(
	'the oEmbed iframe HTML has the announced height, not the WordPress default',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$data = get_oembed_response_data( $fx['ep1'], 600 );
		$t->same( Embed::HEIGHT, (int) $data['height'], 'height' );
		$t->assert( (bool) preg_match( '/<iframe [^>]*width="600" height="' . Embed::HEIGHT . '"/', (string) $data['html'] ), 'iframe height: ' . substr( (string) $data['html'], 0, 300 ) );
		$t->assert( false === strpos( (string) $data['html'], 'height="338"' ), 'no 338px frame' );

		$page = get_oembed_response_data( $fx['shortcodes_page'], 600 );
		$t->assert( Embed::HEIGHT !== (int) $page['height'] && false !== strpos( (string) $page['html'], 'height="' . (int) $page['height'] . '"' ), 'pages keep the WordPress card' );
	}
);

$t->test(
	'the embed document is a playable player card with only podcast assets',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$ep1 = epm()->episodes->get_public_data( $fx['ep1'] );

		wp_styles()->done  = [];
		wp_scripts()->done = [];
		$html = epm_test_as_episode_page(
			(int) $fx['ep1'],
			static function () use ( $ep1 ) {
				return ( new Embed() )->document( $ep1 );
			},
			true
		);

		$t->assert( 0 === strpos( ltrim( $html ), '<!DOCTYPE html>' ), 'complete document' );
		$t->assert( false !== strpos( $html, '<meta name="robots" content="noindex, follow" />' ), 'noindex' );
		$t->assert( false !== strpos( $html, 'rel="canonical" href="' . esc_url( $ep1['url'] ) . '"' ), 'canonical to the episode page' );
		$t->assert( false !== strpos( $html, 'id="epm-design-tokens"' ), 'design tokens' );
		$t->assert( false !== strpos( $html, "id='epm-frontend-css'" ), 'podcast stylesheet' );
		$t->assert( false !== strpos( $html, 'assets/js/epm-player.js' ), 'player engine' );
		$t->assert( false !== strpos( $html, 'assets/js/epm-embed.js' ), 'embed bridge' );
		$t->assert( 1 === substr_count( $html, 'data-epm-player' ), 'one player' );
		$t->assert( false !== strpos( $html, 'epm-player--embed' ), 'embed card layout' );
		$t->assert( false !== strpos( $html, 'data-epm-page-episode' ), '?t= applies inside the embed' );
		$t->assert( false !== strpos( $html, '<a class="epm-player__title-link" href="' . esc_url( $ep1['url'] ) . '" target="_top">' ), 'title links back to the episode' );
		$t->assert( false !== strpos( $html, '<p class="epm-player__label">Test &amp; Talk Podcast</p>' ), 'show name' );
		$t->assert( false !== strpos( $html, 'epm-player__artwork' ), 'artwork' );
		$t->assert( false === strpos( $html, 'data-epm-share' ), 'no share menu in the frame' );
		foreach ( [ 'hello-elementor', 'elementor-frontend', 'wp-embed-template', 'wp-block-library' ] as $foreign ) {
			$t->assert( false === strpos( $html, $foreign ), 'no ' . $foreign );
		}
	}
);

WP_CLI::log( 'Video' );

$t->test(
	'video addresses resolve to click-to-load sources',
	static function ( EPM_Test_Runner $t ) {
		$youtube = [
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
			'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://youtu.be/dQw4w9WgXcQ',
			'https://www.youtube.com/embed/dQw4w9WgXcQ',
			'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
			'https://www.youtube.com/shorts/dQw4w9WgXcQ',
			'https://www.youtube.com/live/dQw4w9WgXcQ?si=x',
		];
		foreach ( $youtube as $url ) {
			$source = Renderer::video_source( $url );
			$t->same( 'youtube', $source['kind'] ?? null, $url );
			$t->same( 'dQw4w9WgXcQ', $source['id'] ?? null, $url );
		}
		$t->same( 'vimeo', Renderer::video_source( 'https://vimeo.com/76979871' )['kind'] ?? null, 'vimeo' );
		$t->same( '76979871', Renderer::video_source( 'https://player.vimeo.com/video/76979871' )['id'] ?? null, 'vimeo player' );
		$t->same( 'file', Renderer::video_source( 'https://cdn.example.org/show/ep1.mp4' )['kind'] ?? null, 'file' );
		$t->same( 'link', Renderer::video_source( 'https://example.org/watch/ep1' )['kind'] ?? null, 'other page' );
		$t->same( null, Renderer::video_source( 'https://www.youtube.com/watch?v=<script>' ), 'invalid id' );
		$t->same( null, Renderer::video_source( '' ), 'empty' );
		$t->same( null, Renderer::video_source( 'not a url' ), 'not a URL' );
	}
);

$t->test(
	'the video facade makes no third-party request before play',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$ep2                = epm()->episodes->get_public_data( $fx['ep2'] );
		$ep2['youtube_url'] = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$html               = epm()->renderer->video( $ep2 );

		$t->assert( false !== strpos( $html, 'data-epm-video-kind="youtube"' ), 'kind' );
		$t->assert( false !== strpos( $html, 'data-epm-video-id="dQw4w9WgXcQ"' ), 'id' );
		$t->assert( (bool) preg_match( '/<button type="button" class="epm-video__facade" data-epm-video-play aria-label="Play video: Episode Two">/', $html ), 'play button with an accessible name' );
		$t->assert( false === stripos( $html, '<iframe' ), 'no iframe' );
		$t->assert( false === stripos( $html, '://www.youtube' ) && false === stripos( $html, 'youtube-nocookie' ) && false === stripos( $html, 'ytimg' ), 'no platform address' );
		$t->same( [], epm_test_foreign_sources( $html ), 'every image comes from this site' );
		$t->assert( false !== strpos( $html, 'The video loads from YouTube when you play it.' ), 'privacy note' );
		$t->assert( false === strpos( epm()->renderer->video( $ep2, [ 'show_note' => false ] ), 'epm-video__note' ), 'note can be hidden' );

		$ep2['youtube_url'] = '';
		$ep2['video_url']   = home_url( '/wp-content/uploads/ep2.mp4' );
		$file               = epm()->renderer->video( $ep2 );
		$t->assert( false !== strpos( $file, 'data-epm-video-kind="file"' ) && false !== strpos( $file, 'data-epm-video-src="' . esc_url( $ep2['video_url'] ) . '"' ), 'video file' );
		$t->assert( false === strpos( $file, '<video' ), 'no <video> before play' );

		$ep2['video_url'] = 'https://example.org/watch/ep2';
		$t->assert( false !== strpos( epm()->renderer->video( $ep2 ), '<a class="epm-video__link" href="https://example.org/watch/ep2">' ), 'unknown platform: a link' );

		$ep2['video_url'] = '';
		$t->same( '', epm()->renderer->video( $ep2 ), 'no video, no markup' );
	}
);

$t->test(
	'the automatic episode page adds the video between player and description',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$id = (int) $fx['ep2'];
		update_post_meta( $id, Episodes::META_PREFIX . 'youtube_url', 'https://youtu.be/dQw4w9WgXcQ' );
		Episodes::clear_data_cache( $id );

		try {
			$html = epm_test_as_episode_page(
				$id,
				static function () {
					return ( new EpisodeTemplate() )->filter_content( '<p>EPM-DESCRIPTION</p>' );
				}
			);
			$player      = strpos( $html, 'data-epm-player' );
			$video       = strpos( $html, 'data-epm-video ' );
			$description = strpos( $html, 'EPM-DESCRIPTION' );
			$t->assert( false !== $player && false !== $video && false !== $description, 'all parts present' );
			$t->assert( $player < $video && $video < $description, 'order: player, video, description' );
			$t->assert( false !== strpos( $html, 'data-epm-share' ), 'the episode page player has a share menu' );
			$t->assert( false !== strpos( $html, 'data-epm-page-episode' ), 'the episode page player takes ?t=' );
			$t->assert( false !== strpos( do_shortcode( '[podcast_video id="' . $id . '"]' ), 'data-epm-video-id="dQw4w9WgXcQ"' ), 'shortcode' );
		} finally {
			delete_post_meta( $id, Episodes::META_PREFIX . 'youtube_url' );
			Episodes::clear_data_cache( $id );
		}
	}
);

WP_CLI::log( 'Topics' );

$t->test(
	'episode lists filter by topic and show topic chips on request',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$tax        = Renderer::TOPIC_TAXONOMY;
		$registered = false;
		if ( ! taxonomy_exists( $tax ) ) {
			register_taxonomy( $tax, EpisodePostType::CPT, [ 'public' => true, 'rewrite' => false ] );
			$registered = true;
		}

		$a = wp_insert_term( 'EPM Test Topic A', $tax, [ 'slug' => 'epm-test-topic-a' ] );
		$b = wp_insert_term( 'EPM Test Topic B', $tax, [ 'slug' => 'epm-test-topic-b' ] );
		$t->assert( ! is_wp_error( $a ) && ! is_wp_error( $b ), 'terms created' );

		try {
			wp_set_object_terms( (int) $fx['ep1'], [ 'epm-test-topic-a' ], $tax, true );
			wp_set_object_terms( (int) $fx['ep2'], [ 'epm-test-topic-b' ], $tax, true );

			$only_a = do_shortcode( '[podcast_episodes limit="20" topic="epm-test-topic-a"]' );
			$t->assert( false !== strpos( $only_a, 'Episode One' ), 'topic A lists episode one' );
			$t->assert( false === strpos( $only_a, 'Episode Two' ), 'topic A excludes episode two' );
			$t->assert( false === strpos( $only_a, 'epm-topics' ), 'no chips by default' );

			$both = do_shortcode( '[podcast_episodes limit="20" topic="epm-test-topic-a, epm-test-topic-b" show_topics="yes"]' );
			$t->assert( false !== strpos( $both, 'Episode One' ) && false !== strpos( $both, 'Episode Two' ), 'two topics list both' );
			$t->assert( false === strpos( $both, 'Episode Three' ), 'other episodes excluded' );
			$t->assert( false !== strpos( $both, '<ul class="epm-topics" aria-label="Topics">' ), 'chips when requested' );
			$t->assert( false !== strpos( $both, '>EPM Test Topic A</' ), 'chip names the topic' );

			$none = do_shortcode( '[podcast_episodes topic="epm-test-topic-none"]' );
			$t->assert( false !== strpos( $none, 'No episodes in this selection yet.' ), 'filtered empty state' );
			$t->assert( false !== strpos( $none, 'epm-episode-list__empty-link' ), 'empty state links to all episodes' );

			$t->same( [], Renderer::topic_query_args( [] ), 'no slugs, no filter' );
			$t->same( [ 'a-b', 'c' ], Renderer::topic_slugs( ' A b ,c,,c ' ), 'slugs are sanitized' );

			$ep1 = epm()->episodes->get_public_data( $fx['ep1'] );
			$t->assert( false !== strpos( epm()->renderer->episode_card( $ep1, [ 'show_topics' => true ] ), 'EPM Test Topic A' ), 'card chips' );
			$t->assert( false !== strpos( epm()->renderer->episode_row( $ep1, [ 'show_topics' => true ] ), 'EPM Test Topic A' ), 'row chips' );
		} finally {
			wp_remove_object_terms( (int) $fx['ep1'], [ 'epm-test-topic-a' ], $tax );
			wp_remove_object_terms( (int) $fx['ep2'], [ 'epm-test-topic-b' ], $tax );
			foreach ( [ $a, $b ] as $term ) {
				if ( is_array( $term ) ) {
					wp_delete_term( (int) $term['term_id'], $tax );
				}
			}
			if ( $registered ) {
				unregister_taxonomy( $tax );
			}
		}

		if ( $registered ) {
			$t->same( [], Renderer::topic_query_args( [ 'x' ] ), 'no taxonomy, no filter' );
			$t->same( '', epm()->renderer->topics( epm()->episodes->get_public_data( $fx['ep1'] ) ), 'no taxonomy, no chips' );
		}
	}
);

WP_CLI::log( 'Players and lists' );

$t->test(
	'audio on another host is not requested before play (preload="none")',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$ep      = epm()->episodes->get_public_data( $fx['ep1'] );
		$preload = static function ( array $episode ): string {
			return preg_match( '/<audio preload="([^"]*)" src="/', epm()->renderer->player( $episode ), $m ) ? $m[1] : '';
		};

		$t->same( 'metadata', $preload( $ep ), 'Media Library file on this site' );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$t->same( 'metadata', $preload( array_merge( $ep, [ 'audio_url' => str_replace( '//' . $host, '//' . strtoupper( $host ), (string) $ep['audio_url'] ) ] ) ), 'host compared case-insensitively' );
		$t->same( 'metadata', $preload( array_merge( $ep, [ 'audio_url' => '/wp-content/uploads/ep1.mp3' ] ) ), 'relative address' );
		foreach ( [ 'https://dts.podtrac.com/redirect.mp3/example.com/ep1.mp3', 'https://anchor.fm/s/1/podcast/play/2/ep1.mp3', 'https://cdn.example.org/wp-content/uploads/ep1.mp3' ] as $url ) {
			$t->same( 'none', $preload( array_merge( $ep, [ 'audio_url' => $url ] ) ), $url );
		}

		$auto  = static function () {
			return 'auto';
		};
		$bogus = static function () {
			return 'eager" onload="x';
		};
		add_filter( 'epm_player_preload', $auto );
		$t->same( 'auto', $preload( $ep ), 'filterable' );
		remove_filter( 'epm_player_preload', $auto );
		add_filter( 'epm_player_preload', $bogus );
		$t->same( 'none', $preload( $ep ), 'unknown filter values fall back to none' );
		remove_filter( 'epm_player_preload', $bogus );
	}
);

$t->test(
	'list play buttons, chapter lists and the episode page bring the sticky player',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$flag = new ReflectionProperty( Assets::class, 'sticky_requested' );
		$flag->setAccessible( true );
		$saved    = $flag->getValue();
		$renderer = epm()->renderer;
		$ep1      = epm()->episodes->get_public_data( $fx['ep1'] );
		$requests = static function ( callable $render ) use ( $flag ): bool {
			$flag->setValue( null, false );
			$render();
			return (bool) $flag->getValue();
		};
		$args     = [];
		$capture  = static function ( $player_args ) use ( &$args ) {
			$args = $player_args;
			return $player_args;
		};

		try {
			$t->assert( ! $requests( static fn() => $renderer->player( $ep1 ) ), 'a player keeps the opt-in' );
			$t->assert( $requests( static fn() => $renderer->player( $ep1, [ 'sticky' => true ] ) ), 'a sticky player' );
			$t->assert( ! $requests( static fn() => $renderer->player( $ep1, [ 'show_chapters_link' => true ] ) ), 'chapters inside a player follow the player' );
			$t->assert( $requests( static fn() => $renderer->episode_row( $ep1 ) ), 'row play button' );
			$t->assert( $requests( static fn() => $renderer->episode_card( $ep1 ) ), 'card play button' );
			$t->assert( $requests( static fn() => $renderer->chapters( $ep1 ) ), 'chapter list without a player' );
			$t->assert( $requests( static fn() => do_shortcode( '[podcast_chapters id="' . $fx['ep1'] . '"]' ) ), 'chapters shortcode' );

			add_filter( 'epm_auto_embed_player_args', $capture );
			$page = $requests(
				static fn() => epm_test_as_episode_page(
					(int) $fx['ep2'],
					static fn() => ( new EpisodeTemplate() )->filter_content( '<p>x</p>' )
				)
			);
			$t->assert( $page && true === ( $args['sticky'] ?? null ), 'automatic episode page player is sticky' );

			// The shell may be on the page for one view and not another:
			// each view says whether playback started there opens the bar.
			$t->assert( false !== strpos( $renderer->player( $ep1 ), 'data-epm-sticky-player="0"' ), 'a player without sticky: its playback leaves the bar closed' );
			$t->assert( false !== strpos( $renderer->player( $ep1, [ 'sticky' => true ] ), 'data-epm-sticky-player="1"' ), 'a sticky player opens it' );
			$t->assert( false !== strpos( $renderer->episode_row( $ep1 ), 'data-epm-sticky-player="1"' ), 'a row button opens it' );
			$t->assert( false !== strpos( $renderer->chapters( $ep1 ), 'data-epm-sticky-player="1"' ), 'a chapter list opens it' );
			$inside = $renderer->player( $ep1, [ 'show_chapters_link' => true ] );
			$t->assert( 1 === substr_count( $inside, 'data-epm-sticky-player=' ), 'chapters inside a player follow the player' );

			add_filter( 'epm_sticky_player_for_lists', '__return_false' );
			$t->assert( ! $requests( static fn() => $renderer->episode_row( $ep1 ) ), 'filter: rows opt out' );
			$t->assert( ! $requests( static fn() => $renderer->chapters( $ep1 ) ), 'filter: chapters opt out' );
			$t->assert( false !== strpos( $renderer->episode_row( $ep1 ), 'data-epm-sticky-player="0"' ), 'filter: a row button leaves the bar closed' );
			$t->assert( false !== strpos( $renderer->chapters( $ep1 ), 'data-epm-sticky-player="0"' ), 'filter: a chapter list leaves it closed' );
		} finally {
			remove_filter( 'epm_auto_embed_player_args', $capture );
			remove_filter( 'epm_sticky_player_for_lists', '__return_false' );
			$flag->setValue( null, $saved );
		}
	}
);

$t->test(
	'list play buttons carry all three labels in one cell, so the width never changes',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$ep1  = epm()->episodes->get_public_data( $fx['ep1'] );
		$html = epm()->renderer->list_play_button( $ep1, 'epm-episode-row__play' );
		$t->assert( (bool) preg_match( '#<span class="epm-list-play__label"><span class="epm-list-play__text--play">Play</span><span class="epm-list-play__text--pause" aria-hidden="true">Pause</span><span class="epm-list-play__text--retry" aria-hidden="true">Retry</span></span></button>$#', $html ), $html );
		$t->assert( false !== strpos( $html, 'aria-label="Play Episode One: Hello' ), 'the name carries the state' );
	}
);

$t->test(
	'row lists reserve the number column only when a listed episode has a number',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$renderer = epm()->renderer;
		$none     = [ get_post( $fx['wav'] ), get_post( $fx['no_audio'] ) ];
		$mixed    = [ get_post( $fx['wav'] ), get_post( $fx['ep1'] ) ];
		$numbered = static function ( string $html ): bool {
			return false !== strpos( $html, 'epm-episode-list--numbered' );
		};

		$t->assert( ! $numbered( $renderer->episode_list( $none, [ 'layout' => 'list' ] ) ), 'no numbers: no column' );
		$t->assert( ! $numbered( $renderer->episode_list( $none, [ 'layout' => 'editorial-rows' ] ) ), 'no numbers: editorial rows' );
		$t->assert( $numbered( $renderer->episode_list( $mixed, [ 'layout' => 'list' ] ) ), 'one number: the column is kept for every row' );
		$t->assert( $numbered( $renderer->episode_list( $mixed, [ 'layout' => 'editorial-rows' ] ) ), 'editorial rows' );
		$t->assert( ! $numbered( $renderer->episode_list( $mixed, [ 'layout' => 'list', 'show_episode_number' => false ] ) ), 'numbers switched off' );
		$t->assert( ! $numbered( $renderer->episode_list( $mixed, [ 'layout' => 'cards' ] ) ), 'cards' );
		$t->same( 2, substr_count( $renderer->episode_list( $mixed, [ 'layout' => 'list' ] ), '<article class="epm-episode-row' ), 'every episode is listed' );
	}
);

$t->test(
	'dark designs give standalone sections the design background and padding',
	static function ( EPM_Test_Runner $t ) {
		// Tokens are printed only where podcast styles are used (WID-N9).
		\EPM\Assets::mark_player_used();
		$tokens = static function ( string $preset ): string {
			$values = array_merge( DesignSettings::defaults(), (array) ( epm()->presets->get( $preset )['tokens'] ?? [] ) );
			$filter = static function () use ( $values ) {
				return $values;
			};
			add_filter( 'pre_option_' . DesignSettings::OPTION, $filter );
			ob_start();
			( new DesignSettings() )->output_tokens();
			$css = html_entity_decode( (string) ob_get_clean(), ENT_QUOTES );
			remove_filter( 'pre_option_' . DesignSettings::OPTION, $filter );
			return $css;
		};

		foreach ( [ 'night-studio', 'midnight', 'business-tuning' ] as $dark ) {
			$css = $tokens( $dark );
			$t->assert( false !== strpos( $css, '--epm-section-background:var(--epm-background' ), $dark . ': section background' );
			$t->assert( false !== strpos( $css, '--epm-section-padding:var(--epm-gap' ), $dark . ': section padding' );
		}
		$t->assert( false === strpos( $tokens( 'neutral' ), '--epm-section-' ), 'light designs set nothing' );

		add_filter( 'epm_dark_section_surface', '__return_false' );
		try {
			$css = $tokens( 'night-studio' );
			$t->assert( false === strpos( $css, '--epm-section-' ), 'sites with dark pages can opt out' );
			$t->assert( false !== strpos( $css, '--epm-image-outline' ), 'the other dark tokens stay' );
		} finally {
			remove_filter( 'epm_dark_section_surface', '__return_false' );
		}

		// Without a :root value the hero falls through to the section surface.
		$sheet = (string) file_get_contents( EPM_PATH . 'assets/css/epm-frontend.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$t->assert( (bool) preg_match( '/:where\(:root\) \{([^}]*)\}/', $sheet, $root ), ':root fallback block found' );
		$t->assert( false === strpos( $root[1] ?? '', '--epm-hero-background' ) && false === strpos( $root[1] ?? '', '--epm-latest-background' ), 'no :root value for the widget backgrounds' );
	}
);

if ( defined( 'ELEMENTOR_VERSION' ) ) {
	$t->test(
		'the hero and latest-episode Background controls also set inner padding',
		static function ( EPM_Test_Runner $t ) {
			$types  = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
			// By ID: outside the editor Elementor keeps style controls apart.
			$hero   = (array) $types['epm-podcast-hero']->get_controls( 'hero_background' );
			$latest = (array) $types['epm-latest-episode']->get_controls( 'latest_background' );
			$t->same( '--epm-hero-background: {{VALUE}}; --epm-hero-padding: calc(var(--epm-gap, 24px) * 1.5);', $hero['selectors']['{{WRAPPER}} .epm-podcast-hero'] ?? null, 'hero' );
			$t->same( '--epm-latest-background: {{VALUE}}; --epm-latest-padding: var(--epm-gap, 24px);', $latest['selectors']['{{WRAPPER}} .epm-latest'] ?? null, 'latest episode' );
			$t->same( [ 'style_source' => 'custom' ], $hero['condition'] ?? null, 'hero: Custom style source only' );
			$t->same( [ 'style_source' => 'custom' ], $latest['condition'] ?? null, 'latest: Custom style source only' );
		}
	);
}

WP_CLI::log( 'Strings' );

$t->test(
	'the player engine carries no strings of its own; PHP localizes them',
	static function ( EPM_Test_Runner $t ) {
		$js = (string) file_get_contents( EPM_PATH . 'assets/js/epm-player.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$t->assert( (bool) preg_match( '/var STR = \{(.*?)\};/s', $js, $m ), 'STR block found' );
		$values = [];
		preg_match_all( "/(\w+):\s*'([^']*)'/", $m[1] ?? '', $pairs, PREG_SET_ORDER );
		foreach ( $pairs as $pair ) {
			$values[ $pair[1] ] = $pair[2];
		}
		$t->assert( count( $values ) >= 10, 'keys parsed' );
		$t->same( [], array_filter( $values ), 'no English fallbacks in the script' );
		$t->assert( array_key_exists( 'startsAt', $values ), 'the ?t= cue string is used by the engine' );

		$data = (string) wp_scripts()->get_data( 'epm-player', 'data' );
		foreach ( array_keys( $values ) as $key ) {
			$t->assert( false !== strpos( $data, '"' . $key . '":"' ), 'localized: ' . $key );
		}
	}
);

$t->test(
	'no plugin-triggered _doing_it_wrong notices during the run',
	static function ( EPM_Test_Runner $t ) {
		$t->same( [], $GLOBALS['epm_test_doing_it_wrong'] );
	}
);

$t->finish();
