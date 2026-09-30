<?php
/**
 * Integration tests for the admin screens, executed inside WordPress after
 * the fixtures are seeded:
 *
 *   wp eval-file tests/integration/admin.php
 *
 * Covers: the Topics taxonomy, the next episode number, transcript files
 * in the episode editor, Quick Edit and Bulk Edit, the design export
 * allowlist and import validation, the Design screen's token table (it
 * must match DesignSettings::output_tokens() for every preset) and the
 * Design screen render.
 *
 * Everything created here is deleted again; changed options are restored.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Admin;
use EPM\DesignSettings;
use EPM\EpisodeMeta;
use EPM\EpisodePostType;
use EPM\Episodes;
use EPM\Transcripts;

require_once __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

$t = new EPM_Test_Runner();

$admin_id = (int) ( get_user_by( 'login', 'admin' )->ID ?? 1 );
wp_set_current_user( $admin_id );

$cleanup = [];

/**
 * Create a test episode.
 *
 * @param array $args Post args.
 * @param array $meta Meta without prefix.
 * @return int
 */
$make_episode = static function ( array $args = [], array $meta = [] ) use ( &$cleanup ): int {
	$id = wp_insert_post(
		array_merge(
			[
				'post_type'   => EpisodePostType::CPT,
				'post_status' => 'draft',
				'post_title'  => 'Admin test episode',
			],
			$args
		)
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, Episodes::META_PREFIX . $key, $value );
	}
	$cleanup[] = (int) $id;

	return (int) $id;
};

/**
 * Create an attachment from bytes (no upload checks: the file type is
 * what is under test).
 *
 * @param string $name  File name.
 * @param string $bytes Contents.
 * @param string $mime  MIME type.
 * @return int
 */
$make_file = static function ( string $name, string $bytes, string $mime ) use ( &$cleanup ): int {
	$upload = wp_upload_bits( $name, null, $bytes );
	$id     = wp_insert_attachment(
		[
			'post_mime_type' => $mime,
			'post_title'     => $name,
			'post_status'    => 'inherit',
		],
		$upload['file']
	);
	$cleanup[] = (int) $id;

	return (int) $id;
};

WP_CLI::log( 'Topics' );

$t->test(
	'podcast_topic is a tag-like taxonomy for episodes with REST, admin column and episode capabilities',
	static function ( EPM_Test_Runner $t ) {
		$tax = get_taxonomy( 'podcast_topic' );
		$t->assert( $tax instanceof WP_Taxonomy, 'registered' );
		$t->same( false, $tax->hierarchical, 'non-hierarchical' );
		$t->same( [ EpisodePostType::CPT ], array_values( $tax->object_type ) );
		$t->same( 'podcast-topic', $tax->rewrite['slug'] );
		$t->assert( $tax->show_in_rest, 'show_in_rest' );
		$t->assert( $tax->show_admin_column, 'show_admin_column' );
		$t->assert( $tax->show_in_quick_edit, 'quick edit' );
		$t->same( 'Topics', $tax->labels->name );
		foreach ( [ 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ] as $cap ) {
			$t->same( 'edit_posts', $tax->cap->$cap, $cap );
		}
	}
);

$t->test(
	'topic capabilities follow the filtered episode capability',
	static function ( EPM_Test_Runner $t ) {
		$filter = static function () {
			return 'manage_podcast_episodes';
		};
		add_filter( 'epm_cap_manage_episodes', $filter );
		EpisodePostType::register();
		$t->same( 'manage_podcast_episodes', get_taxonomy( 'podcast_topic' )->cap->assign_terms );
		remove_filter( 'epm_cap_manage_episodes', $filter );
		EpisodePostType::register();
		$t->same( 'edit_posts', get_taxonomy( 'podcast_topic' )->cap->assign_terms );
	}
);

$t->test(
	'the Topics screen is linked under the Podcast menu',
	static function ( EPM_Test_Runner $t ) {
		$t->same( 'edit-tags.php?taxonomy=podcast_topic&post_type=podcast_episode', Admin::topics_menu_slug() );
	}
);

WP_CLI::log( 'Episode editor' );

$t->test(
	'next episode number: highest number in use plus one, per season, ignoring trash and the edited episode',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$before = EpisodeMeta::next_episode_numbers();
		$t->assert( $before['all'] >= 1, 'a number is always suggested' );

		$a = $make_episode( [ 'post_status' => 'draft' ], [ 'episode_number' => 500, 'season_number' => 7 ] );
		$make_episode( [ 'post_status' => 'publish' ], [ 'episode_number' => 498, 'season_number' => 7 ] );
		$make_episode( [ 'post_status' => 'pending' ], [ 'episode_number' => 3, 'season_number' => 8 ] );
		$trash = $make_episode( [ 'post_status' => 'publish' ], [ 'episode_number' => 900, 'season_number' => 7 ] );
		wp_trash_post( $trash );

		$next = EpisodeMeta::next_episode_numbers();
		$t->same( 501, $next['all'], 'overall' );
		$t->same( 501, $next['seasons']['7'] ?? null, 'season 7' );
		$t->same( 4, $next['seasons']['8'] ?? null, 'season 8' );

		$without = EpisodeMeta::next_episode_numbers( $a );
		$t->same( 499, $without['seasons']['7'] ?? null, 'the edited episode is left out' );
	}
);

$t->test(
	'the episode information box offers the next number without prefilling the field',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$id = $make_episode();
		ob_start();
		( new EpisodeMeta() )->box_details( get_post( $id ) );
		$html = (string) ob_get_clean();
		$t->assert( false !== strpos( $html, 'data-epm-next-numbers=' ), 'suggestion data' );
		$t->assert( false !== strpos( $html, 'data-epm-next-number' ), 'Use next number button' );
		$t->assert( (bool) preg_match( '/id="epm-episode-number"[^>]*value=""/', $html ), 'field stays empty' );
	}
);

$t->test(
	'the chapters box offers the paste-chapters disclosure',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$id = $make_episode();
		ob_start();
		( new EpisodeMeta() )->box_chapters( get_post( $id ) );
		$html = (string) ob_get_clean();
		$t->assert( false !== strpos( $html, 'data-epm-paste-chapters' ), 'disclosure' );
		$t->assert( (bool) preg_match( '/<label[^>]*for="epm-paste-chapters-text"/', $html ), 'labelled textarea' );
		$t->assert( ! preg_match( '/<textarea[^>]*name=/', $html ), 'the pasted text is never saved itself' );
	}
);

/**
 * Save an episode through the editor's save handler.
 *
 * @param int   $post_id Post ID.
 * @param array $fields  Fields of $_POST['epm'].
 * @return void
 */
$editor_save = static function ( int $post_id, array $fields ): void {
	$_POST['epm_episode_meta_nonce'] = wp_create_nonce( 'epm_episode_meta' );
	$_POST['epm']                    = $fields;
	( new EpisodeMeta() )->save( $post_id, get_post( $post_id ) );
	unset( $_POST['epm_episode_meta_nonce'], $_POST['epm'] );
	Episodes::clear_data_cache( $post_id );
};

$t->test(
	'a transcript file fills an empty transcript text on save and is listed for the feed',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $make_file, $editor_save ) {
		$vtt = $make_file( 'admin-test.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:04.000\n<v Host>Welcome to the show.\n\n00:00:04.000 --> 00:00:08.000\n<v Guest>Thanks for having me.\n", 'text/vtt' );
		$t->assert( EpisodeMeta::is_transcript_attachment( $vtt ), 'VTT accepted' );

		$id = $make_episode();
		$editor_save(
			$id,
			[
				'transcript_file_id' => (string) $vtt,
				'transcript'         => '',
			]
		);

		$t->same( $vtt, (int) get_post_meta( $id, '_epm_transcript_file_id', true ), 'file saved' );
		$text = (string) get_post_meta( $id, '_epm_transcript', true );
		$t->assert( false !== strpos( $text, 'Welcome to the show.' ) && false !== strpos( $text, 'Thanks for having me.' ), 'text filled from the file: ' . $text );
		$t->assert( false === strpos( $text, '-->' ), 'cue timings are not copied as text' );

		$files = Transcripts::files( $id );
		$t->same( 'text/vtt', $files[0]['type'] ?? '', 'feed lists the file' );

		// A typed transcript is never replaced.
		$editor_save(
			$id,
			[
				'transcript_file_id' => (string) $vtt,
				'transcript'         => '<p>My own words.</p>',
			]
		);
		$t->same( '<p>My own words.</p>', get_post_meta( $id, '_epm_transcript', true ), 'typed text kept' );

		// Removing the file.
		$editor_save(
			$id,
			[
				'transcript_file_id' => '',
				'transcript'         => '<p>My own words.</p>',
			]
		);
		$t->same( '', get_post_meta( $id, '_epm_transcript_file_id', true ), 'file removed' );
	}
);

$t->test(
	'an SRT transcript file is accepted, other files are rejected with the stored file kept',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $make_file, $editor_save ) {
		$srt   = $make_file( 'admin-test.srt', "1\n00:00:00,000 --> 00:00:03,000\nHello from SRT.\n", 'application/x-subrip' );
		$image = $make_file( 'admin-test.txt', 'not a transcript', 'text/plain' );
		$t->assert( EpisodeMeta::is_transcript_attachment( $srt ), 'SRT accepted' );
		$t->assert( ! EpisodeMeta::is_transcript_attachment( $image ), 'plain text rejected' );

		$id = $make_episode();
		$editor_save( $id, [ 'transcript_file_id' => (string) $srt ] );
		$t->same( $srt, (int) get_post_meta( $id, '_epm_transcript_file_id', true ) );
		$t->assert( false !== strpos( (string) get_post_meta( $id, '_epm_transcript', true ), 'Hello from SRT.' ), 'SRT text' );

		$editor_save( $id, [ 'transcript_file_id' => (string) $image ] );
		$t->same( $srt, (int) get_post_meta( $id, '_epm_transcript_file_id', true ), 'invalid file does not replace the stored one' );
	}
);

$t->test(
	'the transcript box shows where an imported transcript file lives',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$id = $make_episode( [], [ 'transcript_url' => 'https://cdn.example.test/show/ep1.vtt', 'transcript_type' => 'text/vtt' ] );
		ob_start();
		( new EpisodeMeta() )->box_transcript( get_post( $id ) );
		$html = (string) ob_get_clean();
		$t->assert( false !== strpos( $html, 'cdn.example.test' ), 'host named' );
		$t->assert( false !== strpos( $html, 'https://cdn.example.test/show/ep1.vtt' ), 'address linked' );
		$t->assert( false !== strpos( $html, 'WebVTT' ), 'format named' );
		$t->assert( (bool) preg_match( '/<label[^>]*for="epm_transcript"/', $html ), 'transcript text labelled' );
	}
);

WP_CLI::log( 'Episode list' );

$t->test(
	'Quick Edit saves number, season, type and explicit; invalid values are ignored',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$id = $make_episode( [], [ 'episode_number' => 5, 'season_number' => 2, 'episode_type' => 'full', 'explicit' => 'inherit' ] );

		$_POST['epm_quick_nonce'] = wp_create_nonce( 'epm_quick_edit' );
		$_POST['epm_quick']       = [
			'episode_number' => '12',
			'season_number'  => '',
			'episode_type'   => 'trailer',
			'explicit'       => 'clean',
		];
		( new Admin() )->save_inline_edit( $id, get_post( $id ) );
		$t->same( '12', (string) get_post_meta( $id, '_epm_episode_number', true ) );
		$t->same( '', (string) get_post_meta( $id, '_epm_season_number', true ), 'an emptied season is cleared' );
		$t->same( 'trailer', get_post_meta( $id, '_epm_episode_type', true ) );
		$t->same( 'clean', get_post_meta( $id, '_epm_explicit', true ) );

		$_POST['epm_quick'] = [
			'episode_number' => 'abc',
			'season_number'  => '-3',
			'episode_type'   => 'weird',
			'explicit'       => [ 'x' ],
		];
		( new Admin() )->save_inline_edit( $id, get_post( $id ) );
		$t->same( '12', (string) get_post_meta( $id, '_epm_episode_number', true ), 'text is not a number' );
		$t->same( 'trailer', get_post_meta( $id, '_epm_episode_type', true ), 'unknown type ignored' );
		$t->same( 'clean', get_post_meta( $id, '_epm_explicit', true ), 'array ignored' );

		// A bad nonce changes nothing.
		$_POST['epm_quick_nonce'] = 'bad';
		$_POST['epm_quick']       = [ 'episode_number' => '99' ];
		( new Admin() )->save_inline_edit( $id, get_post( $id ) );
		$t->same( '12', (string) get_post_meta( $id, '_epm_episode_number', true ), 'nonce checked' );

		unset( $_POST['epm_quick_nonce'], $_POST['epm_quick'] );
	}
);

$t->test(
	'Quick Edit requires permission to edit the episode',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $admin_id ) {
		$id    = $make_episode( [ 'post_status' => 'publish' ], [ 'episode_number' => 5 ] );
		$login = 'epm_admin_test_subscriber';
		$user  = username_exists( $login ) ?: wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
		( new WP_User( $user ) )->set_role( 'subscriber' );
		wp_set_current_user( $user );
		$_POST['epm_quick_nonce'] = wp_create_nonce( 'epm_quick_edit' );
		$_POST['epm_quick']       = [ 'episode_number' => '77' ];
		( new Admin() )->save_inline_edit( $id, get_post( $id ) );
		wp_set_current_user( $admin_id );
		unset( $_POST['epm_quick_nonce'], $_POST['epm_quick'] );
		wp_delete_user( $user );
		$t->same( '5', (string) get_post_meta( $id, '_epm_episode_number', true ) );
	}
);

$t->test(
	'Bulk Edit numbers the selection by publish date and leaves "no change" fields alone',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$newer = $make_episode( [ 'post_status' => 'publish', 'post_date' => '2026-03-01 10:00:00' ], [ 'episode_number' => 1, 'episode_type' => 'bonus', 'explicit' => 'inherit', 'season_number' => 4 ] );
		$older = $make_episode( [ 'post_status' => 'publish', 'post_date' => '2026-01-01 10:00:00' ], [ 'episode_number' => 2, 'episode_type' => 'full', 'explicit' => 'inherit', 'season_number' => 4 ] );

		$_REQUEST['epm_bulk_nonce'] = wp_create_nonce( 'epm_bulk_edit' );
		$_REQUEST['epm_bulk']       = [
			'number_from'   => '20',
			'season_number' => '',
			'episode_type'  => '',
			'explicit'      => 'explicit',
		];
		$_REQUEST['post'] = [ (string) $newer, (string) $older ];
		foreach ( [ $newer, $older ] as $id ) {
			( new Admin() )->save_inline_edit( $id, get_post( $id ) );
		}
		unset( $_REQUEST['epm_bulk_nonce'], $_REQUEST['epm_bulk'], $_REQUEST['post'] );

		$t->same( '20', (string) get_post_meta( $older, '_epm_episode_number', true ), 'oldest first' );
		$t->same( '21', (string) get_post_meta( $newer, '_epm_episode_number', true ) );
		$t->same( 'bonus', get_post_meta( $newer, '_epm_episode_type', true ), 'type unchanged' );
		$t->same( 'full', get_post_meta( $older, '_epm_episode_type', true ), 'type unchanged' );
		$t->same( '4', (string) get_post_meta( $older, '_epm_season_number', true ), 'empty season means no change' );
		$t->same( 'explicit', get_post_meta( $newer, '_epm_explicit', true ) );
	}
);

$t->test(
	'the episode number column carries the raw values for Quick Edit',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$id = $make_episode( [], [ 'episode_number' => 8, 'season_number' => 3, 'episode_type' => 'bonus', 'explicit' => 'clean' ] );
		ob_start();
		( new Admin() )->render_column( 'epm_episode_no', $id );
		$html = (string) ob_get_clean();
		$t->assert( false !== strpos( $html, 'id="epm-inline-' . $id . '"' ), 'data element' );
		$t->assert( false !== strpos( $html, 'data-number="8"' ) && false !== strpos( $html, 'data-season="3"' ), 'numbers' );
		$t->assert( false !== strpos( $html, 'data-type="bonus"' ) && false !== strpos( $html, 'data-explicit="clean"' ), 'choices' );

		ob_start();
		( new Admin() )->quick_edit_box( 'epm_episode_no', EpisodePostType::CPT );
		$box = (string) ob_get_clean();
		$t->assert( false !== strpos( $box, 'name="epm_quick_nonce"' ), 'nonce field' );
		foreach ( [ 'episode_number', 'season_number', 'episode_type', 'explicit' ] as $field ) {
			$t->assert( false !== strpos( $box, 'name="epm_quick[' . $field . ']"' ), $field );
		}
		ob_start();
		( new Admin() )->bulk_edit_box( 'epm_episode_no', EpisodePostType::CPT );
		$bulk = (string) ob_get_clean();
		$t->assert( substr_count( $bulk, '— No change —' ) >= 2, 'no-change choices' );
	}
);

WP_CLI::log( 'Design' );

$t->test(
	'the design export allowlist carries the 1.3.0 tokens and nothing environment-specific',
	static function ( EPM_Test_Runner $t ) {
		$keys = Admin::design_export_keys();
		foreach ( [ 'button_shape', 'font_family', 'shadow', 'track_color' ] as $key ) {
			$t->assert( in_array( $key, $keys, true ), $key );
		}
		foreach ( $keys as $key ) {
			$t->assert( ! preg_match( '/_id$|url|link/', $key ), 'no IDs or URLs: ' . $key );
		}
	}
);

$t->test(
	'design import validates format, version and every token value',
	static function ( EPM_Test_Runner $t ) {
		$t->assert( is_wp_error( Admin::design_from_payload( [ 'format' => 'other' ] ) ), 'wrong format' );
		$t->assert( is_wp_error( Admin::design_from_payload( [ 'format' => 'epm-design', 'version' => DesignSettings::EXPORT_VERSION + 1, 'design' => [] ] ) ), 'newer version' );
		$t->assert( is_wp_error( Admin::design_from_payload( [ 'format' => 'epm-design', 'version' => '1', 'design' => [] ] ) ), 'string version' );
		$t->assert( is_wp_error( Admin::design_from_payload( [ 'format' => 'epm-design', 'version' => 1 ] ) ), 'no design map' );

		$ok = Admin::design_from_payload(
			[
				'format'  => 'epm-design',
				'version' => 1,
				'preset'  => 'no-such-preset',
				'design'  => [
					'accent'       => '#123456',
					'button_shape' => 'square',
					'font_family'  => 'serif',
					'shadow'       => 'lifted',
					'track_color'  => '#abcdef',
					'text'         => [ 'nested' => 'array' ],
					'muted'        => 'red',
					'secret_url'   => 'https://example.com',
				],
			]
		);
		$t->assert( is_array( $ok ), 'valid payload' );
		$t->same( '#123456', $ok['accent'] );
		$t->same( 'square', $ok['button_shape'] );
		$t->same( 'serif', $ok['font_family'] );
		$t->same( 'lifted', $ok['shadow'] );
		$t->same( '#abcdef', $ok['track_color'] );
		$t->same( DesignSettings::defaults()['muted'], $ok['muted'], 'invalid color falls back' );
		$t->assert( '#' === substr( (string) $ok['text'], 0, 1 ), 'array value ignored, no error' );
		$t->assert( ! isset( $ok['secret_url'] ), 'unknown keys dropped' );

		$bad = Admin::design_from_payload(
			[
				'format'  => 'epm-design',
				'version' => 1,
				'design'  => [
					'button_shape' => 'blob',
					'font_family'  => 'comic',
					'shadow'       => 'huge',
					'track_color'  => '',
				],
			]
		);
		$t->assert( in_array( $bad['button_shape'], array_keys( DesignSettings::button_shapes() ), true ), 'unknown shape rejected' );
		$t->assert( in_array( $bad['font_family'], array_keys( DesignSettings::font_stacks() ), true ), 'unknown font rejected' );
		$t->assert( in_array( $bad['shadow'], array_keys( DesignSettings::shadows() ), true ), 'unknown shadow rejected' );
		$t->same( '', $bad['track_color'], 'automatic track' );
	}
);

$t->test(
	'the Design preview table produces the same variables as the site for every preset',
	static function ( EPM_Test_Runner $t ) {
		foreach ( epm()->presets->all() as $id => $preset ) {
			$values = epm()->design->sanitize( array_merge( DesignSettings::defaults(), (array) $preset['tokens'] ) );
			$filter = static function () use ( $values ) {
				return $values;
			};
			add_filter( 'pre_option_' . DesignSettings::OPTION, $filter );
			ob_start();
			( new DesignSettings() )->output_tokens();
			$css = html_entity_decode( (string) ob_get_clean(), ENT_QUOTES );
			remove_filter( 'pre_option_' . DesignSettings::OPTION, $filter );

			$site = [];
			if ( preg_match( '/:root\{(.*)\}/s', $css, $match ) ) {
				foreach ( array_filter( explode( ';', $match[1] ) ) as $declaration ) {
					[ $name, $value ] = array_map( 'trim', explode( ':', $declaration, 2 ) );
					$site[ $name ]    = $value;
				}
			}
			$preview = Admin::design_css_vars( $values );
			ksort( $site );
			ksort( $preview );
			$t->same( $site, $preview, (string) $id );
		}
	}
);

$t->test(
	'the Design script gets every token with its variable and the helper values',
	static function ( EPM_Test_Runner $t ) {
		$config = Admin::design_preview_config();
		foreach ( Admin::design_export_keys() as $key ) {
			$t->assert( isset( $config['map'][ $key ] ), 'map: ' . $key );
		}
		$t->same( DesignSettings::button_shapes(), (array) $config['map']['button_shape']['values'] );
		$t->same( DesignSettings::font_stacks(), (array) $config['map']['font_family']['values'] );
		$t->same( DesignSettings::shadows(), (array) $config['map']['shadow']['values'] );
		$t->same( '--epm-track', $config['map']['track_color']['var'] );
		$t->assert( count( $config['presets'] ) === count( epm()->presets->all() ), 'all presets' );
		$t->assert( ! empty( $config['presets']['neutral']['values']['accent'] ), 'preset values' );
	}
);

$t->test(
	'contrast ratio follows WCAG',
	static function ( EPM_Test_Runner $t ) {
		$t->same( 21.0, round( Admin::contrast_ratio( '#000000', '#ffffff' ), 2 ) );
		$t->same( 1.0, round( Admin::contrast_ratio( '#777', '#777777' ), 2 ) );
		$t->same( 4.54, round( Admin::contrast_ratio( '#767676', '#ffffff' ), 2 ) );
		$t->same( 1.0, Admin::contrast_ratio( 'nope', '#ffffff' ), 'invalid color' );
	}
);

$t->test(
	'the Design screen renders presets, grouped fields, contrast checks and the live preview',
	static function ( EPM_Test_Runner $t ) {
		ob_start();
		( new Admin() )->render_design();
		$html = (string) ob_get_clean();

		foreach ( array_keys( epm()->presets->all() ) as $id ) {
			$t->assert( false !== strpos( $html, 'name="epm_preset" value="' . $id . '"' ), 'preset tile ' . $id );
		}
		foreach ( [ 'accent', 'track_color', 'border_radius', 'button_shape', 'shadow', 'font_family', 'default_player_layout' ] as $key ) {
			$t->assert( false !== strpos( $html, 'name="epm_design_settings[' . $key . ']"' ), 'field ' . $key );
		}
		$t->same( 5, substr_count( $html, 'data-epm-contrast-pair' ), 'contrast pairs' );
		$t->assert( false !== strpos( $html, 'data-epm-preview-canvas' ) && false !== strpos( $html, 'data-epm-player' ), 'preview renders a real player' );
		$t->assert( false !== strpos( $html, 'epm-episode-card' ) && false !== strpos( $html, 'epm-episode-row' ), 'cards and rows' );
		$t->assert( false !== strpos( $html, 'epm-subscribe' ), 'subscribe links' );
		$t->assert( false !== strpos( $html, 'data-epm-preset-dialog' ), 'confirmation dialog' );
		$t->assert( (bool) preg_match( '/<label[^>]*for="epm-design-file"/', $html ), 'import file labelled' );
		$t->assert( false !== strpos( $html, '--epm-accent:' ), 'preview carries the design variables' );
	}
);

$t->test(
	'the Design preview falls back to neutral sample episodes',
	static function ( EPM_Test_Runner $t ) {
		$samples = Admin::sample_episodes();
		$t->same( 2, count( $samples ) );
		$html = epm()->renderer->player( $samples[0], [ 'layout' => 'full' ] );
		$t->assert( false !== strpos( $html, 'data-epm-player' ), 'sample renders a player' );
		$t->assert( false !== strpos( epm()->renderer->episode_card( $samples[1] ), 'epm-episode-card' ), 'sample card' );
	}
);

// Clean up.
foreach ( array_reverse( $cleanup ) as $id ) {
	if ( 'attachment' === get_post_type( $id ) ) {
		wp_delete_attachment( $id, true );
	} else {
		wp_delete_post( $id, true );
	}
}
wp_set_current_user( 0 );

$t->finish();
