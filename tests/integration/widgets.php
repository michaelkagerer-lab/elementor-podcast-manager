<?php
/**
 * Integration tests for widget and shortcode findings of the 1.3.0 audit:
 *
 * - WID-N3  ordering by episode number keeps episodes without a number;
 * - WID-N7  toggle descriptions and the call to action without a URL;
 * - WID-N8  the metadata separator keeps its spaces;
 * - WID-N9  nothing loads where nothing podcast-related renders;
 * - the Latest Episode widget and [podcast_latest] offer the sticky player;
 * - the volume slider has one accessible name.
 *
 *   wp eval-file tests/integration/widgets.php
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Assets;
use EPM\Episodes;

require_once __DIR__ . '/lib.php';

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

$t   = new EPM_Test_Runner();
$ep1 = (int) $fx['ep1'];

/**
 * Episode titles of a rendered list, in order.
 */
$titles = static function ( string $html ): array {
	preg_match_all( '/class="epm-episode-(?:row|card)__title">(?:<a[^>]*>)?([^<]+)/', $html, $m );
	return array_map(
		static function ( $title ) {
			return str_replace( [ '“', '”' ], '"', html_entity_decode( $title ) );
		},
		$m[1]
	);
};

/**
 * A control of a widget type.
 */
$control = static function ( string $type, string $id ): array {
	return (array) ( epm_test_controls( $type )[ $id ] ?? [] );
};

/**
 * Render with the Elementor editor switched on.
 */
$in_editor = static function ( callable $fn ) {
	$editor = \Elementor\Plugin::$instance->editor;
	$editor->set_edit_mode( true );
	try {
		return $fn();
	} finally {
		$editor->set_edit_mode( false );
	}
};

WP_CLI::log( 'Episode number order (WID-N3)' );

$t->test(
	'ordering by episode number lists every episode, numbered first, the rest by date',
	static function ( EPM_Test_Runner $t ) use ( $titles ) {
		$by_date = $titles( do_shortcode( '[podcast_episodes limit="20"]' ) );
		$desc    = $titles( do_shortcode( '[podcast_episodes limit="20" orderby="episode_number"]' ) );
		$asc     = $titles( do_shortcode( '[podcast_episodes limit="20" orderby="episode_number" order="ASC"]' ) );
		$t->same( count( $by_date ), count( $desc ), 'same episodes as by date: ' . wp_json_encode( $desc ) );
		$t->same( [ 'Episode Three (bonus)', 'Episode Two', 'Episode One: Hello & "Friends"', 'WAV only', 'No Audio Yet' ], $desc, 'descending' );
		$t->same( [ 'Episode One: Hello & "Friends"', 'Episode Two', 'Episode Three (bonus)', 'No Audio Yet', 'WAV only' ], $asc, 'ascending' );

		$widget = $titles( epm_test_widget( 'epm-episode-list', [ 'number' => 20, 'orderby' => 'episode_number', 'layout' => 'list' ] ) );
		$t->same( $desc, $widget, 'Episode List widget' );

		$paged = epm()->episodes->query( [ 'orderby' => 'episode_number', 'posts_per_page' => 2, 'paged' => 3 ] );
		$t->same( 5, (int) $paged->found_posts, 'pagination counts every episode' );
		$t->same( [ 'No Audio Yet' ], wp_list_pluck( $paged->posts, 'post_title' ), 'last page' );
	}
);

WP_CLI::log( 'Toggle descriptions and calls to action (WID-N7)' );

$t->test(
	'the episode label and the Latest Episode details say when they show',
	static function ( EPM_Test_Runner $t ) use ( $control ) {
		$label = $control( 'epm-podcast-player', 'show_episode_label' );
		$t->assert( false !== stripos( (string) ( $label['description'] ?? '' ), 'number' ) && false !== stripos( (string) $label['description'], 'bonus' ), 'the label explains what it contains: ' . ( $label['description'] ?? '' ) );
		foreach ( [ 'show_artwork' => 'Minimal', 'show_description' => 'Compact' ] as $id => $layout ) {
			$c = $control( 'epm-latest-episode', $id );
			$t->assert( false !== strpos( (string) ( $c['description'] ?? '' ), $layout ), 'Latest Episode ' . $id . ' notes the ' . $layout . ' layout' );
		}
	}
);

$t->test(
	'a call to action without a link explains itself in the editor and renders nothing on the site',
	static function ( EPM_Test_Runner $t ) use ( $in_editor ) {
		foreach ( [ 'epm-latest-episode', 'epm-podcast-hero' ] as $type ) {
			$settings = [ 'show_cta' => 'yes', 'cta_text' => 'Listen' ];
			$site     = epm_test_widget( $type, $settings );
			$t->assert( false === strpos( $site, '__cta' ) && false === strpos( $site, 'epm-editor-placeholder' ), $type . ': nothing on the site' );
			$editor = $in_editor( static fn() => epm_test_widget( $type, $settings ) );
			$t->assert( false !== strpos( $editor, 'epm-editor-placeholder' ) && false !== stripos( $editor, 'link' ), $type . ': editor hint' );
			$with = epm_test_widget( $type, $settings + [ 'cta_url' => [ 'url' => 'https://example.org/' ] ] );
			$t->assert( false !== strpos( $with, 'href="https://example.org/"' ), $type . ': with a link' );
		}
	}
);

WP_CLI::log( 'Metadata separator (WID-N8)' );

$t->test(
	'the separator keeps its spaces and custom separators render exactly',
	static function ( EPM_Test_Runner $t ) use ( $ep1 ) {
		$text = static function ( string $html ): string {
			return trim( html_entity_decode( wp_strip_all_tags( $html ) ) );
		};
		$base = [ 'source' => 'specific', 'episode_id' => $ep1, 'fields' => [ 'episode_number', 'duration' ] ];
		$t->same( 'Episode 1 · 1:35', $text( epm_test_widget( 'epm-episode-metadata', $base ) ), 'default separator' );
		$t->same( 'Episode 1, 1:35', $text( epm_test_widget( 'epm-episode-metadata', $base + [ 'separator' => ', ' ] ) ), 'comma' );
		$t->same( 'Episode 1|1:35', $text( epm_test_widget( 'epm-episode-metadata', $base + [ 'separator' => '|' ] ) ), 'no spaces added' );
		$t->assert( false === strpos( epm_test_widget( 'epm-episode-metadata', $base + [ 'separator' => ' <b>x</b> ' ] ), '<b>' ), 'markup stripped' );
	}
);

WP_CLI::log( 'Nothing loads where nothing renders (WID-N9)' );

$t->test(
	'a widget or shortcode that renders nothing enqueues nothing',
	static function ( EPM_Test_Runner $t ) {
		$check = static function ( string $label, callable $render ) use ( $t ) {
			wp_dequeue_style( 'epm-frontend' );
			wp_dequeue_script( 'epm-player' );
			Assets::reset_usage();
			$html = $render();
			$t->assert( '' === trim( wp_strip_all_tags( $html, true ) ) && false === strpos( $html, 'epm-' . 'player' ), $label . ' renders nothing' );
			$t->assert( ! wp_style_is( 'epm-frontend', 'enqueued' ) && ! wp_script_is( 'epm-player', 'enqueued' ), $label . ': no assets' );
			$t->assert( ! Assets::is_used(), $label . ': not marked as used' );
		};
		$check( 'Player widget, current episode on a page', static fn() => epm_test_widget( 'epm-podcast-player', [ 'source' => 'current' ] ) );
		$check( 'Chapters widget, current episode on a page', static fn() => epm_test_widget( 'epm-chapters', [ 'source' => 'current' ] ) );
		$check( 'Video widget, current episode on a page', static fn() => epm_test_widget( 'epm-episode-video', [ 'source' => 'current' ] ) );
		$check( '[podcast_player id] of a draft', static fn() => do_shortcode( '[podcast_player id="' . (int) get_option( 'epm_test_fixtures' )['draft'] . '"]' ) );
		$check( '[podcast_video] without a video', static fn() => do_shortcode( '[podcast_video id="' . (int) get_option( 'epm_test_fixtures' )['ep2'] . '"]' ) );
		Assets::reset_usage();
	}
);

$t->test(
	'design tokens are printed only where podcast styles are used',
	static function ( EPM_Test_Runner $t ) {
		$print = static function (): string {
			ob_start();
			( new \EPM\DesignSettings() )->output_tokens();
			return (string) ob_get_clean();
		};
		Assets::reset_usage();
		wp_dequeue_style( 'epm-frontend' );
		$t->same( '', $print(), 'nothing used: no tokens' );
		Assets::enqueue_style();
		$t->assert( false !== strpos( $print(), 'epm-design-tokens' ), 'styles used: tokens' );
		Assets::reset_usage();
		wp_dequeue_style( 'epm-frontend' );
	}
);

WP_CLI::log( 'Sticky player for the latest episode' );

$t->test(
	'the Latest Episode widget and [podcast_latest] offer the sticky player, off by default',
	static function ( EPM_Test_Runner $t ) use ( $control ) {
		$sticky = static function ( string $html ): string {
			return preg_match( '/data-epm-player[^>]*data-epm-sticky-player="(\d)"/', $html, $m ) ? $m[1] : '';
		};
		$c = $control( 'epm-latest-episode', 'sticky' );
		$t->assert( ! empty( $c ), 'Latest Episode has a sticky control' );
		$t->same( '', $c['default'] ?? null, 'off by default' );
		$t->same( '0', $sticky( epm_test_widget( 'epm-latest-episode', [] ) ), 'widget default' );
		$t->same( '1', $sticky( epm_test_widget( 'epm-latest-episode', [ 'sticky' => 'yes' ] ) ), 'widget on' );
		$t->same( '0', $sticky( do_shortcode( '[podcast_latest]' ) ), 'shortcode default' );
		$t->same( '1', $sticky( do_shortcode( '[podcast_latest sticky="yes"]' ) ), 'shortcode on' );
	}
);

WP_CLI::log( 'Player markup' );

$t->test(
	'the volume slider is named once',
	static function ( EPM_Test_Runner $t ) use ( $ep1 ) {
		$html = do_shortcode( '[podcast_player id="' . $ep1 . '" layout="full"]' );
		$t->assert( (bool) preg_match( '/<label class="epm-player__volume">(.*?)<\/label>/s', $html, $m ), 'volume label' );
		$inner = $m[1] ?? '';
		$names = (int) preg_match( '/<input[^>]*aria-label=/', $inner ) + (int) preg_match( '/epm-sr-only[^>]*>[^<]+</', $inner );
		$t->same( 1, $names, 'one name source: ' . $inner );
		$t->assert( false !== stripos( wp_strip_all_tags( $inner ) . ( preg_match( '/aria-label="([^"]+)"/', $inner, $a ) ? $a[1] : '' ), 'Volume' ), 'named "Volume"' );
	}
);

$t->test(
	'every detail control is Default / Show / Hide with Default as the default',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ 'epm-podcast-player', 'epm-latest-episode', 'epm-episode-list' ] as $type ) {
			$controls = epm_test_controls( $type );
			foreach ( $controls as $id => $c ) {
				if ( 0 === strpos( (string) $id, 'show_' ) && 'select' === ( $c['type'] ?? '' ) ) {
					$t->same( [ '', 'yes', 'no' ], array_keys( (array) $c['options'] ), $type . '.' . $id . ' is Default/Show/Hide' );
					$t->same( '', $c['default'] ?? null, $type . '.' . $id . ' defaults to Default' );
				}
			}
		}
	}
);

$t->finish();
