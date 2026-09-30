<?php
/**
 * Integration tests for the frontend components (1.3.0): timestamp links,
 * the share menu, episode embeds, the video facade and topic filters.
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
 * Run a callback as if the request were the single page (or embed) of an
 * episode, then restore the previous query.
 *
 * @param int      $post_id Episode ID.
 * @param callable $fn      Callback.
 * @param bool     $embed   Pretend to be /podcast/{slug}/embed/.
 * @return mixed Callback result.
 */
function epm_test_as_episode_page( int $post_id, callable $fn, bool $embed = false ) {
	global $wp_query, $wp_the_query, $post;

	$saved = [ $wp_query, $wp_the_query, $post ];

	$wp_query = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		[
			'p'         => $post_id,
			'post_type' => EpisodePostType::CPT,
		]
	);
	if ( $embed ) {
		$wp_query->is_embed = true;
	}
	$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$post         = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );

	try {
		return $fn();
	} finally {
		[ $wp_query, $wp_the_query, $post ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
	}
}

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
