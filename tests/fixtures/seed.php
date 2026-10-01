<?php
/**
 * Test fixtures. Run with: wp eval-file tests/fixtures/seed.php
 *
 * Creates podcast settings, artwork, generated audio files and episodes in
 * every visibility state, plus a shortcode page and an Elementor page with
 * all podcast widgets. Idempotent: previous fixtures are removed first.
 * Prints the fixture IDs as JSON (also stored in the epm_test_fixtures
 * option for the other suites).
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Silent MPEG-1 Layer III stream (32 kbps, 32 kHz, mono) of $seconds.
 * Every frame is a valid header plus zeroed side info/main data.
 *
 * @param int $seconds Duration.
 * @return string
 */
function epm_fixture_mp3( int $seconds ): string {
	$frame  = "\xFF\xFB\x18\xC0" . str_repeat( "\0", 140 ); // 144-byte frame = 36 ms.
	$frames = (int) ceil( $seconds / 0.036 );

	return str_repeat( $frame, $frames );
}

/**
 * Silent 16-bit mono PCM WAV of $seconds.
 *
 * @param int $seconds Duration.
 * @return string
 */
function epm_fixture_wav( int $seconds ): string {
	$rate = 8000;
	$data = str_repeat( "\0\0", $rate * $seconds );

	return 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVE'
		. 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 )
		. 'data' . pack( 'V', strlen( $data ) ) . $data;
}

/**
 * Square PNG artwork.
 *
 * @param int $size Edge length.
 * @param int $width Optional width for non-square images.
 * @return string
 */
function epm_fixture_png( int $size, int $width = 0 ): string {
	$im = imagecreatetruecolor( $width > 0 ? $width : $size, $size );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 29, 78, 216 ) );
	ob_start();
	imagepng( $im );
	imagedestroy( $im );

	return (string) ob_get_clean();
}

/**
 * Import bytes as an attachment.
 *
 * @param string $name  File name.
 * @param string $bytes Contents.
 * @return int
 */
function epm_fixture_attachment( string $name, string $bytes ): int {
	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], 0 );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $name . ': ' . $id->get_error_message() );
	}

	return (int) $id;
}

// Destructive: this resets every episode. Disposable test sites only.
if ( 'production' === wp_get_environment_type() && ! getenv( 'EPM_ALLOW_TEST_SEED' ) ) {
	WP_CLI::error( 'Refusing to reset episodes on a production site. Set WP_ENVIRONMENT_TYPE to "local" (or EPM_ALLOW_TEST_SEED=1) on a disposable test install.' );
}

foreach ( get_posts( [ 'post_type' => 'podcast_episode', 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ] ) as $episode_id ) {
	wp_delete_post( $episode_id, true );
}

// Remove previous fixtures.
$previous = get_option( 'epm_test_fixtures', [] );
if ( is_array( $previous ) ) {
	foreach ( $previous as $id ) {
		if ( is_int( $id ) && $id > 0 ) {
			wp_delete_post( $id, true );
		}
	}
}

$ids = [];

$ids['artwork']      = epm_fixture_attachment( 'epm-artwork.png', epm_fixture_png( 1400 ) );
$ids['wide_image']   = epm_fixture_attachment( 'epm-wide.png', epm_fixture_png( 450, 800 ) );
$ids['square_image'] = epm_fixture_attachment( 'epm-square.png', epm_fixture_png( 1400 ) );
$ids['audio_1']      = epm_fixture_attachment( 'epm-episode-1.mp3', epm_fixture_mp3( 95 ) );
$ids['audio_2']      = epm_fixture_attachment( 'epm-episode-2.mp3', epm_fixture_mp3( 150 ) );
$ids['audio_3']      = epm_fixture_attachment( 'epm-episode-3.mp3', epm_fixture_mp3( 62 ) );
$ids['audio_wav']    = epm_fixture_attachment( 'epm-master.wav', epm_fixture_wav( 3 ) );

update_option(
	'epm_podcast_settings',
	[
		'title'          => 'Test & Talk Podcast',
		'description'    => '<p>A show about <strong>testing</strong> & tooling.</p>',
		'author'         => 'Jane Host',
		'owner_name'     => 'Jane Host',
		'owner_email'    => 'jane@example.com',
		'category'       => 'Technology',
		'subcategory'    => '',
		'language'       => 'de_AT',
		'copyright'      => '© 2026 Test',
		'type'           => 'episodic',
		'explicit'       => 'clean',
		'website_url'    => home_url( '/' ),
		'artwork_id'     => $ids['artwork'],
		'feed_limit'     => 500,
		'auto_embed'     => true,
		'platform_links' => [
			[ 'service' => 'spotify', 'label' => 'Spotify', 'url' => 'https://open.spotify.com/show/example' ],
			[ 'service' => 'apple', 'label' => 'Apple Podcasts', 'url' => 'https://podcasts.apple.com/podcast/example' ],
		],
	]
);
delete_option( 'epm_design_settings' );

$make = static function ( array $post, array $meta ) {
	$id = wp_insert_post( array_merge( [ 'post_type' => 'podcast_episode', 'post_status' => 'publish' ], $post ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, '_epm_' . $key, $value );
	}
	EPM\Episodes::get_guid( $id );
	EPM\Episodes::sync_duration_seconds( $id );

	return (int) $id;
};

$ids['ep1'] = $make(
	[
		'post_title'   => 'Episode One: Hello & "Friends"',
		'post_content' => "<p>First episode with <a href=\"https://example.com\">a link</a>.</p>\n<p>Second paragraph.</p>",
		'post_date'    => '2026-06-01 10:00:00',
	],
	[
		'audio_id'          => $ids['audio_1'],
		'episode_number'    => 1,
		'season_number'     => 1,
		'episode_type'      => 'full',
		'explicit'          => 'inherit',
		'guest_name'        => 'Max Guest',
		'guest_role'        => 'CTO',
		'guest_company'     => 'ACME',
		'guest_bio'         => "Line one\nLine two",
		'short_description' => 'Short summary of episode one.',
		'show_notes'        => '<h3>Links</h3><ul><li><a href="https://example.com">Example</a></li></ul>',
		'transcript'        => "<p><strong>Host:</strong> Hello.</p>\n<p><strong>Guest:</strong> Hi there.</p>",
		'chapters'          => [
			[ 'time' => '0:00', 'title' => 'Intro', 'url' => '' ],
			[ 'time' => '0:30', 'title' => 'Main topic', 'url' => 'https://example.com/topic' ],
			[ 'time' => '1:10', 'title' => 'Outro', 'url' => '' ],
		],
		'artwork_id'        => $ids['square_image'],
	]
);
$ids['ep2'] = $make(
	[ 'post_title' => 'Episode Two', 'post_content' => 'Plain content for episode two.', 'post_date' => '2026-07-01 10:00:00' ],
	[ 'audio_id' => $ids['audio_2'], 'episode_number' => 2, 'season_number' => 1, 'explicit' => 'explicit' ]
);
set_post_thumbnail( $ids['ep2'], $ids['wide_image'] );
$ids['ep3'] = $make(
	[ 'post_title' => 'Episode Three (bonus)', 'post_content' => 'Bonus.', 'post_date' => '2026-08-01 10:00:00' ],
	[ 'audio_id' => $ids['audio_3'], 'episode_number' => 3, 'season_number' => 2, 'episode_type' => 'bonus' ]
);
$ids['no_audio'] = $make( [ 'post_title' => 'No Audio Yet', 'post_date' => '2026-09-01 10:00:00' ], [] );
$ids['wav']      = $make( [ 'post_title' => 'WAV only', 'post_date' => '2026-09-02 10:00:00' ], [ 'audio_id' => $ids['audio_wav'] ] );
$ids['draft']    = $make( [ 'post_title' => 'Draft Secret', 'post_status' => 'draft' ], [ 'audio_id' => $ids['audio_1'], 'chapters' => [ [ 'time' => '0:00', 'title' => 'x', 'url' => '' ] ] ] );
$ids['private']  = $make( [ 'post_title' => 'Private Secret', 'post_status' => 'private' ], [ 'audio_id' => $ids['audio_1'] ] );
$ids['password'] = $make( [ 'post_title' => 'Password Secret', 'post_password' => 'pw' ], [ 'audio_id' => $ids['audio_2'], 'transcript' => 'secret words' ] );
$ids['future']   = $make( [ 'post_title' => 'Future Secret', 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) ], [ 'audio_id' => $ids['audio_3'] ] );

// Shortcode page.
$ids['shortcodes_page'] = (int) wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'EPM Shortcodes',
		'post_name'    => 'epm-shortcodes',
		'post_content' => "[podcast_player id=\"{$ids['ep1']}\"]\n\n[podcast_episodes limit=\"10\" layout=\"cards\"]\n\n[podcast_subscribe]\n\n[podcast_chapters id=\"{$ids['ep1']}\"]",
	]
);

// Elementor page with every podcast widget.
if ( defined( 'ELEMENTOR_VERSION' ) ) {
	$widgets  = [
		[ 'epm-podcast-hero', [] ],
		[ 'epm-podcast-player', [ 'source' => 'specific', 'episode_id' => $ids['ep1'], 'layout' => 'full', 'sticky' => 'yes', 'style_source' => 'custom', 'play_button_background' => '#0a7d33', 'play_button_size' => [ 'unit' => 'px', 'size' => 72 ] ] ],
		[ 'epm-episode-list', [ 'layout' => 'cards', 'number' => 4, 'pagination' => 'numbered' ] ],
		[ 'epm-latest-episode', [] ],
		[ 'epm-episode-header', [ 'source' => 'specific', 'episode_id' => $ids['ep1'] ] ],
		[ 'epm-episode-metadata', [ 'source' => 'specific', 'episode_id' => $ids['ep1'], 'fields' => [ 'episode_number', 'date', 'duration', 'guest' ] ] ],
		[ 'epm-guest', [ 'source' => 'specific', 'episode_id' => $ids['ep1'] ] ],
		[ 'epm-subscribe-links', [] ],
		[ 'epm-transcript', [ 'source' => 'specific', 'episode_id' => $ids['ep1'] ] ],
		[ 'epm-show-notes', [ 'source' => 'specific', 'episode_id' => $ids['ep1'] ] ],
		[ 'epm-chapters', [ 'source' => 'specific', 'episode_id' => $ids['ep1'] ] ],
	];
	$elements = [];
	foreach ( $widgets as $i => [ $type, $settings ] ) {
		$elements[] = [ 'id' => substr( md5( $type . $i ), 0, 7 ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ];
	}
	$ids['elementor_page'] = (int) wp_insert_post(
		[ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'EPM Elementor', 'post_name' => 'epm-elementor' ]
	);
	update_post_meta( $ids['elementor_page'], '_elementor_edit_mode', 'builder' );
	update_post_meta( $ids['elementor_page'], '_elementor_template_type', 'wp-page' );
	update_post_meta( $ids['elementor_page'], '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $ids['elementor_page'], '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'c0ffee1', 'elType' => 'container', 'settings' => [], 'elements' => $elements, 'isInner' => false ] ] ) ) );
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}

EPM\Feed::flush_cache();
update_option( 'epm_test_fixtures', $ids, false );

echo wp_json_encode( $ids ) . "\n";
