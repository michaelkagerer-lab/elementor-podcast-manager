<?php
/**
 * Integration tests for the admin screens, executed inside WordPress after
 * the fixtures are seeded:
 *
 *   wp eval-file tests/integration/admin.php
 *
 * Covers: the Topics taxonomy and its capabilities, the Podcast menu, the
 * episode list's default columns, the next episode number, transcript
 * files in the episode editor, what contributors can reach through the
 * episode search and media AJAX, Quick Edit and Bulk Edit, the design
 * export allowlist and import validation, the Design screen's token table
 * (it must match DesignSettings::output_tokens() for every preset), the
 * contrast pairs, the Design and Settings screen renders and the
 * readiness note.
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

/**
 * Users created by the run (deleted at the end, with their posts).
 *
 * @var int[] $users
 */
$users = [];

/**
 * Create (or reuse) a test user with a role.
 *
 * @param string $role Role.
 * @return int
 */
$make_user = static function ( string $role ) use ( &$users ): int {
	$login = 'epm_admin_test_' . $role;
	$id    = username_exists( $login ) ?: wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
	( new WP_User( $id ) )->set_role( $role );
	$users[] = (int) $id;

	return (int) $id;
};

/**
 * Run an AJAX handler as the current user and return the decoded JSON.
 * wp_send_json() ends with wp_die(), which is turned into an exception.
 *
 * @param callable $handler Handler.
 * @param array    $post    Request fields ($_POST and $_REQUEST).
 * @return array
 */
$ajax = static function ( callable $handler, array $post ): array {
	$die = static function () {
		return static function () {
			throw new RuntimeException( 'epm-ajax-done' );
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$_POST    = $post;
	$_REQUEST = $post;
	ob_start();
	try {
		$handler();
	} catch ( RuntimeException $e ) {
		if ( 'epm-ajax-done' !== $e->getMessage() ) {
			throw $e;
		}
	} finally {
		$output = (string) ob_get_clean();
		$_POST    = [];
		$_REQUEST = [];
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$data = json_decode( $output, true );

	return is_array( $data ) ? $data : [ 'raw' => $output ];
};

WP_CLI::log( 'Topics' );

$t->test(
	'UX-N8: the unminified admin script name cannot be mistaken for a min.js catalog',
	static function ( EPM_Test_Runner $t ) {
		Admin::enqueue_assets();
		$script = wp_scripts()->registered['epm-admin'];
		$t->assert( ! preg_match( '/min\.js$/', basename( $script->src ) ), 'wp i18n make-json hashes the actual unminified script path' );
	}
);

$t->test(
	'podcast_topic is a tag-like taxonomy: episode editors assign topics, managing them needs manage_categories',
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
		foreach ( [ 'manage_terms', 'edit_terms', 'delete_terms' ] as $cap ) {
			$t->same( 'manage_categories', $tax->cap->$cap, $cap );
		}
		$t->same( 'edit_posts', $tax->cap->assign_terms, 'assign_terms' );
	}
);

$t->test(
	'topic capabilities follow the filtered episode capability and the topics filter',
	static function ( EPM_Test_Runner $t ) {
		$filter = static function () {
			return 'manage_podcast_episodes';
		};
		add_filter( 'epm_cap_manage_episodes', $filter );
		EpisodePostType::register();
		foreach ( [ 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ] as $cap ) {
			$t->same( 'manage_podcast_episodes', get_taxonomy( 'podcast_topic' )->cap->$cap, $cap );
		}
		remove_filter( 'epm_cap_manage_episodes', $filter );

		$topics = static function () {
			return 'edit_others_posts';
		};
		add_filter( 'epm_cap_manage_topics', $topics );
		EpisodePostType::register();
		$t->same( 'edit_others_posts', get_taxonomy( 'podcast_topic' )->cap->edit_terms, 'topics filter' );
		remove_filter( 'epm_cap_manage_topics', $topics );

		$meta = static function () {
			return 'edit_term';
		};
		add_filter( 'epm_cap_manage_topics', $meta );
		EpisodePostType::register();
		$t->same( 'manage_categories', get_taxonomy( 'podcast_topic' )->cap->edit_terms, 'a meta capability is never used' );
		remove_filter( 'epm_cap_manage_topics', $meta );

		EpisodePostType::register();
		$t->same( 'edit_posts', get_taxonomy( 'podcast_topic' )->cap->assign_terms );
		$t->same( 'manage_categories', get_taxonomy( 'podcast_topic' )->cap->manage_terms );
	}
);

$t->test(
	'contributors tag episodes with topics (and add new ones) but cannot rename or delete topics',
	static function ( EPM_Test_Runner $t ) use ( $make_user, $admin_id ) {
		$term = wp_insert_term( 'EPM admin test topic', EpisodePostType::TOPIC );
		$t->assert( is_array( $term ), 'topic created' );
		$term_id     = (int) $term['term_id'];
		$contributor = $make_user( 'contributor' );
		$editor      = $make_user( 'editor' );
		$created     = 0;

		wp_set_current_user( $contributor );
		$t->assert( current_user_can( 'assign_term', $term_id ), 'contributor assigns' );
		$t->assert( ! current_user_can( 'edit_term', $term_id ), 'contributor cannot edit' );
		$t->assert( ! current_user_can( 'delete_term', $term_id ), 'contributor cannot delete' );

		$rename = new WP_REST_Request( 'POST', '/wp/v2/' . EpisodePostType::TOPIC . '/' . $term_id );
		$rename->set_param( 'name', 'Renamed by a contributor' );
		$t->same( 403, rest_do_request( $rename )->get_status(), 'REST rename refused' );
		$delete = new WP_REST_Request( 'DELETE', '/wp/v2/' . EpisodePostType::TOPIC . '/' . $term_id );
		$delete->set_param( 'force', true );
		$t->same( 403, rest_do_request( $delete )->get_status(), 'REST delete refused' );

		$create = new WP_REST_Request( 'POST', '/wp/v2/' . EpisodePostType::TOPIC );
		$create->set_param( 'name', 'EPM contributor topic' );
		$response = rest_do_request( $create );
		$t->same( 201, $response->get_status(), 'a new topic can still be added while tagging' );
		$created = (int) ( $response->get_data()['id'] ?? 0 );

		wp_set_current_user( $editor );
		$t->assert( current_user_can( 'edit_term', $term_id ) && current_user_can( 'delete_term', $term_id ), 'editors manage topics' );

		wp_set_current_user( $admin_id );
		$t->same( 'EPM admin test topic', get_term( $term_id )->name, 'name unchanged' );
		wp_delete_term( $term_id, EpisodePostType::TOPIC );
		if ( $created > 0 ) {
			wp_delete_term( $created, EpisodePostType::TOPIC );
		}
	}
);

$t->test(
	'the Podcast menu uses sentence case and lists Topics only for users who can manage them',
	static function ( EPM_Test_Runner $t ) use ( $make_user, $admin_id ) {
		global $submenu, $menu;
		$saved = [ $submenu, $menu ];
		$build = static function (): array {
			global $submenu;
			$submenu = [];
			( new Admin() )->register_menu();
			$items = [];
			foreach ( (array) ( $submenu['epm-dashboard'] ?? [] ) as $item ) {
				$items[ (string) $item[2] ] = [ (string) $item[0], (string) $item[1] ];
			}
			return $items;
		};

		$items = $build();
		$t->same( 'Add episode', $items[ 'post-new.php?post_type=' . EpisodePostType::CPT ][0] ?? '', 'add episode' );
		$t->same( 'Podcast settings', $items['epm-settings'][0] ?? '', 'settings' );
		$t->same( 'manage_categories', $items[ Admin::topics_menu_slug() ][1] ?? '', 'Topics needs the topic capability' );
		$t->same( 'edit-tags.php?taxonomy=podcast_topic&post_type=podcast_episode', Admin::topics_menu_slug() );

		wp_set_current_user( $make_user( 'contributor' ) );
		$items = $build();
		$t->assert( isset( $items[ 'edit.php?post_type=' . EpisodePostType::CPT ] ), 'contributors see Episodes' );
		$t->assert( ! isset( $items[ Admin::topics_menu_slug() ] ), 'contributors do not see Topics' );

		wp_set_current_user( $admin_id );
		[ $submenu, $menu ] = $saved;
	}
);

$t->test(
	'episode labels use sentence case',
	static function ( EPM_Test_Runner $t ) {
		$labels = get_post_type_object( EpisodePostType::CPT )->labels;
		$t->same( 'Add episode', $labels->add_new_item );
		$t->same( 'Edit episode', $labels->edit_item );
		$t->same( 'Search episodes', $labels->search_items );
	}
);

$t->test(
	'the episode list hides Author, and Topics while none exist, until Screen Options are saved',
	static function ( EPM_Test_Runner $t ) {
		$screen = WP_Screen::get( 'edit-' . EpisodePostType::CPT );
		$other  = WP_Screen::get( 'edit-post' );
		$admin  = new Admin();

		$t->same( [], $admin->default_hidden_columns( [], $other ), 'other screens untouched' );
		$t->assert( in_array( 'author', $admin->default_hidden_columns( [], $screen ), true ), 'author hidden' );

		$topic   = 'taxonomy-' . EpisodePostType::TOPIC;
		$count   = (int) wp_count_terms(
			[
				'taxonomy'   => EpisodePostType::TOPIC,
				'hide_empty' => false,
			]
		);
		$created = 0;
		if ( 0 === $count ) {
			$t->assert( in_array( $topic, $admin->default_hidden_columns( [], $screen ), true ), 'topics hidden while none exist' );
			$created = (int) ( wp_insert_term( 'EPM column test topic', EpisodePostType::TOPIC )['term_id'] ?? 0 );
		}
		$t->assert( ! in_array( $topic, $admin->default_hidden_columns( [], $screen ), true ), 'topics shown once one exists' );
		if ( $created > 0 ) {
			wp_delete_term( $created, EpisodePostType::TOPIC );
		}
	}
);

WP_CLI::log( 'Episode editor' );

$t->test(
	'SEC-N5: custom-capability episode editors can use that episode’s unpublished media',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $make_file, $make_user, $ajax ) {
		$old_user = get_current_user_id();
		$id = $make_episode( [ 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ] );
		$audio = $make_file( 'custom-caps.mp3', str_repeat( "\xff\xfb\x90\x00", 100 ), 'audio/mpeg' );
		$transcript = $make_file( 'custom-caps.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n", 'text/vtt' );
		foreach ( [ $audio, $transcript ] as $file ) {
			wp_update_post( [ 'ID' => $file, 'post_parent' => $id ] );
		}
		$user_id = $make_user( 'subscriber' );
		$user = new WP_User( $user_id );
		$user->add_cap( 'epm_test_edit_episodes' );
		$filter = static function () { return 'epm_test_edit_episodes'; };
		add_filter( 'epm_cap_manage_episodes', $filter );
		EpisodePostType::register();
		wp_set_current_user( $user_id );
		try {
			$t->assert( current_user_can( 'edit_post', $id ) && ! current_user_can( 'read_post', $audio ), 'precondition: episode rights exceed core attachment rights' );
			$result = $ajax( [ new EpisodeMeta(), 'ajax_audio_describe' ], [ '_ajax_nonce' => wp_create_nonce( 'epm_episode_meta' ), 'post_id' => $id, 'attachment_id' => $audio ] );
			$t->assert( ! empty( $result['success'] ), 'the audio picker accepts the editable episode’s file' );
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'meta', [ '_epm_audio_id' => $audio, '_epm_transcript_file_id' => $transcript ] );
			$t->assert( ! is_wp_error( epm()->rest_check_attachment_meta( (object) [ 'ID' => $id ], $request ) ), 'REST accepts editable-episode audio and captions' );
			$_POST = [ 'epm_episode_meta_nonce' => wp_create_nonce( 'epm_episode_meta' ), 'epm' => [ 'audio_id' => $audio, 'transcript_file_id' => $transcript ] ];
			( new EpisodeMeta() )->save( $id, get_post( $id ) );
			$t->same( [ $audio, $transcript ], [ (int) get_post_meta( $id, '_epm_audio_id', true ), (int) get_post_meta( $id, '_epm_transcript_file_id', true ) ], 'the editor saves both associations' );
		} finally {
			$_POST = [];
			remove_filter( 'epm_cap_manage_episodes', $filter );
			EpisodePostType::register();
			$user->remove_cap( 'epm_test_edit_episodes' );
			wp_set_current_user( $old_user );
		}
	}
);

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
		$t->assert( (bool) preg_match( '/<input type="checkbox" data-epm-paste-replace \/>/', $html ), 'pasting adds by default (replace is not checked)' );
	}
);

$t->test(
	'the video fields explain which formats work and which one wins',
	static function ( EPM_Test_Runner $t ) use ( $make_episode ) {
		$id = $make_episode();
		ob_start();
		( new EpisodeMeta() )->box_notes( get_post( $id ) );
		$html = (string) ob_get_clean();
		$t->assert( (bool) preg_match( '/id="epm-video-url"[^>]*aria-describedby="epm-video-url-help"/', $html ) && false !== strpos( $html, 'id="epm-video-url-help"' ), 'video URL help' );
		$t->assert( (bool) preg_match( '/id="epm-youtube-url"[^>]*aria-describedby="epm-youtube-url-help"/', $html ) && false !== strpos( $html, 'id="epm-youtube-url-help"' ), 'YouTube URL help' );
	}
);

$t->test(
	'episode and guest artwork must be a readable image attachment',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $make_file, $make_user ) {
		$id = $make_episode();
		$private_episode = $make_episode( [ 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ] );
		$foreign = $make_file( 'private-artwork.png', "\x89PNG\r\n\x1a\n", 'image/png' );
		$plain = $make_file( 'not-artwork.txt', 'text', 'text/plain' );
		wp_update_post( [ 'ID' => $foreign, 'post_parent' => $private_episode ] );
		$editor = $make_user( 'contributor' );
		wp_update_post( [ 'ID' => $id, 'post_author' => $editor ] );
		wp_set_current_user( $editor );
		$t->assert( ! current_user_can( 'read_post', $foreign ), 'precondition: the contributor cannot read the image attached to another author\'s scheduled episode' );
		$_POST['epm_episode_meta_nonce'] = wp_create_nonce( 'epm_episode_meta' );
		$_POST['epm'] = [ 'artwork_id' => $foreign, 'guest_image_id' => $plain ];
		( new EpisodeMeta() )->save( $id, get_post( $id ) );
		$t->same( [ 0, 0 ], [ (int) get_post_meta( $id, '_epm_artwork_id', true ), (int) get_post_meta( $id, '_epm_guest_image_id', true ) ] );
		wp_set_current_user( 1 );
		unset( $_POST['epm_episode_meta_nonce'], $_POST['epm'] );
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

$t->test(
	'episode search: contributors see what the Episodes list shows them, never other authors\' private episodes',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $make_user, $ajax, $admin_id ) {
		$public  = $make_episode( [ 'post_status' => 'publish', 'post_title' => 'Zqxv search public', 'post_author' => $admin_id ] );
		$draft   = $make_episode( [ 'post_status' => 'draft', 'post_title' => 'Zqxv search draft', 'post_author' => $admin_id ] );
		$private = $make_episode( [ 'post_status' => 'private', 'post_title' => 'Zqxv search private', 'post_author' => $admin_id ] );
		$search  = static function ( array $fields ) use ( $ajax ): array {
			$response = $ajax(
				[ new EpisodeMeta(), 'ajax_episode_search' ],
				array_merge( [ '_ajax_nonce' => wp_create_nonce( 'epm_episode_search' ) ], $fields )
			);
			return array_map( 'intval', array_column( (array) ( $response['data']['items'] ?? [] ), 'id' ) );
		};

		wp_set_current_user( $make_user( 'contributor' ) );
		$found = $search( [ 's' => 'Zqxv search' ] );
		$t->assert( in_array( $public, $found, true ) && in_array( $draft, $found, true ), 'published and drafts, as in the Episodes list' );
		$t->assert( ! in_array( $private, $found, true ), 'another author\'s private episode is not listed' );
		$t->same( [], $search( [ 'include' => (string) $private ] ), 'nor returned by ID' );
		$own = $make_episode( [ 'post_status' => 'private', 'post_title' => 'Zqxv search own private', 'post_author' => get_current_user_id() ] );
		$t->assert( in_array( $own, $search( [ 's' => 'Zqxv search' ] ), true ), 'their own private episode is listed' );

		wp_set_current_user( $make_user( 'editor' ) );
		$t->assert( in_array( $private, $search( [ 's' => 'Zqxv search' ] ), true ), 'editors find private episodes' );
		$t->same( [ $private ], $search( [ 'include' => (string) $private ] ), 'editors get it by ID' );

		wp_set_current_user( $admin_id );
	}
);

$t->test(
	'media attached to another author\'s unpublished episode is not described, attached or copied for a contributor',
	static function ( EPM_Test_Runner $t ) use ( $make_episode, $make_file, $make_user, $ajax, $editor_save, $admin_id ) {
		// Media uploaded in the editor of the admin's scheduled episode.
		$embargoed_ep = $make_episode(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_author' => $admin_id,
			]
		);
		$audio        = $make_file( 'admin-test-embargo.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00", 'audio/mpeg' );
		$vtt          = $make_file( 'admin-test-embargo.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:03.000\nEmbargoed words.\n", 'text/vtt' );
		foreach ( [ $audio, $vtt ] as $file ) {
			wp_update_post(
				[
					'ID'          => $file,
					'post_parent' => $embargoed_ep,
				]
			);
		}

		$contributor = $make_user( 'contributor' );
		wp_set_current_user( $contributor );
		$t->assert( ! current_user_can( 'read_post', $audio ), 'precondition: the contributor cannot read the file' );

		$describe = static function ( int $attachment, int $post_id ) use ( $ajax ): array {
			return $ajax(
				[ new EpisodeMeta(), 'ajax_audio_describe' ],
				[
					'_ajax_nonce'   => wp_create_nonce( 'epm_episode_meta' ),
					'attachment_id' => (string) $attachment,
					'post_id'       => (string) $post_id,
				]
			);
		};
		$denied = $describe( $audio, 0 );
		$t->same( false, $denied['success'] ?? null, 'describe refused' );
		$t->assert( false === strpos( wp_json_encode( $denied ), 'admin-test-embargo' ), 'no file address returned' );

		// Their own upload in their own draft works.
		$own_ep    = $make_episode( [ 'post_author' => $contributor ] );
		$own_audio = $make_file( 'admin-test-own.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00", 'audio/mpeg' );
		wp_update_post(
			[
				'ID'          => $own_audio,
				'post_parent' => $own_ep,
			]
		);
		$t->same( $contributor, (int) get_post_field( 'post_author', $own_audio ), 'precondition: own upload' );
		$t->same( true, $describe( $own_audio, $own_ep )['success'] ?? null, 'own upload described' );

		// Saving the other author's files into their own draft is refused.
		$editor_save(
			$own_ep,
			[
				'audio_id'           => (string) $audio,
				'transcript_file_id' => (string) $vtt,
				'transcript'         => '',
			]
		);
		$t->same( 0, (int) get_post_meta( $own_ep, '_epm_audio_id', true ), 'audio not attached' );
		$t->same( '', (string) get_post_meta( $own_ep, '_epm_transcript_file_id', true ), 'transcript file not attached' );
		$t->assert( false === strpos( (string) get_post_meta( $own_ep, '_epm_transcript', true ), 'Embargoed words.' ), 'transcript text not copied' );

		// A file stored earlier (by an editor) survives the contributor's re-save.
		update_post_meta( $own_ep, '_epm_audio_id', $audio );
		update_post_meta( $own_ep, '_epm_transcript_file_id', $vtt );
		$editor_save(
			$own_ep,
			[
				'audio_id'           => (string) $audio,
				'transcript_file_id' => (string) $vtt,
				'transcript'         => '<p>Typed.</p>',
			]
		);
		$t->same( $audio, (int) get_post_meta( $own_ep, '_epm_audio_id', true ), 'stored audio kept' );
		$t->same( $vtt, (int) get_post_meta( $own_ep, '_epm_transcript_file_id', true ), 'stored transcript file kept' );

		// The REST API's meta field applies the same rule.
		$rest    = static function ( int $post_id, array $meta ): int {
			$request = new WP_REST_Request( 'POST', '/wp/v2/' . EpisodePostType::CPT . '/' . $post_id );
			$request->set_body_params( [ 'meta' => $meta ] );
			return rest_do_request( $request )->get_status();
		};
		$rest_ep = $make_episode( [ 'post_author' => $contributor ] );
		$t->same( 403, $rest( $rest_ep, [ '_epm_audio_id' => $audio ] ), 'REST: the other author\'s audio is refused' );
		$t->same( 0, (int) get_post_meta( $rest_ep, '_epm_audio_id', true ), 'REST: nothing stored' );
		$t->same( 403, $rest( $rest_ep, [ '_epm_transcript_file_id' => $vtt ] ), 'REST: the other author\'s transcript file is refused' );
		$t->same( 400, $rest( $rest_ep, [ '_epm_transcript_file_id' => $own_audio ] ), 'REST: an audio file is not a transcript file (invalid type, rather than denied access)' );
		$t->same( 200, $rest( $rest_ep, [ '_epm_audio_id' => $own_audio ] ), 'REST: their own audio is accepted' );
		$t->same( $own_audio, (int) get_post_meta( $rest_ep, '_epm_audio_id', true ), 'REST: their own audio is stored' );
		$t->same( 200, $rest( $own_ep, [ '_epm_audio_id' => $audio, '_epm_transcript_file_id' => $vtt ] ), 'REST: unchanged stored files pass' );
		$t->same( $audio, (int) get_post_meta( $own_ep, '_epm_audio_id', true ), 'REST: stored audio kept' );

		wp_set_current_user( $admin_id );
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
		// Tokens are printed only where podcast styles are used (WID-N9).
		\EPM\Assets::mark_player_used();
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
	'the contrast check covers surfaces and accent text, and every shipped preset passes it',
	static function ( EPM_Test_Runner $t ) {
		$pairs = array_map(
			static function ( array $pair ): string {
				return $pair['fg'] . '/' . $pair['bg'];
			},
			Admin::contrast_pairs()
		);
		foreach ( [ 'text/background', 'text/surface', 'muted/background', 'muted/surface', 'accent/background', 'on_accent/accent', 'track_color/background', 'track_color/surface' ] as $pair ) {
			$t->assert( in_array( $pair, $pairs, true ), 'pair ' . $pair );
		}
		$t->same( count( $pairs ), count( array_unique( $pairs ) ), 'pairs are unique' );

		foreach ( epm()->presets->all() as $id => $preset ) {
			$values = epm()->design->sanitize( array_merge( DesignSettings::defaults(), (array) $preset['tokens'] ) );
			foreach ( Admin::contrast_pairs() as $pair ) {
				$fg    = (string) ( $values[ $pair['fg'] ] ?? '' );
				$fg    = '' !== $fg ? $fg : (string) $values['muted'];
				$ratio = floor( Admin::contrast_ratio( $fg, (string) $values[ $pair['bg'] ] ) * 10 ) / 10;
				$t->assert( $ratio >= $pair['min'], $id . ': ' . $pair['fg'] . ' on ' . $pair['bg'] . ' ' . $ratio );
			}
		}
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
		$t->same( count( Admin::contrast_pairs() ), substr_count( $html, 'data-epm-contrast-pair' ), 'contrast pairs' );
		$t->assert( false !== strpos( $html, 'data-epm-design-export' ), 'export form marked (it does not warn about unsaved changes)' );
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

WP_CLI::log( 'Settings and dashboard' );

$t->test(
	'the settings screen names the presenter, content rating and episode order in plain words',
	static function ( EPM_Test_Runner $t ) {
		ob_start();
		( new Admin() )->render_settings();
		$html = (string) ob_get_clean();
		$t->assert( false !== strpos( $html, 'Host (presenter)' ) && (bool) preg_match( '/id="epm-s-host"[^>]*aria-describedby="epm-s-host-help"/', $html ), 'presenter field with help' );
		$t->assert( false !== strpos( $html, 'Suitable for all ages' ) && false !== strpos( $html, 'Newest first (episodic)' ), 'content and episode order' );
		$t->assert( false !== strpos( $html, 'Feed and links' ) && false !== strpos( $html, 'Copy feed address' ), 'feed vocabulary' );
		$t->assert( false === strpos( $html, '>Distribution<' ), 'no second "Distribution"' );
	}
);

$t->test(
	'the readiness note links to the delivery test instead of transport jargon',
	static function ( EPM_Test_Runner $t ) use ( $make_user, $admin_id ) {
		if ( \EPM\Hosting::is_external() ) {
			$t->assert( true );
			return;
		}
		$report = [
			'ready'    => true,
			'errors'   => 0,
			'warnings' => 0,
			'checks'   => [],
		];
		$html   = \EPM\Readiness::render_html( $report );
		$t->assert( false === strpos( $html, 'byte-range' ), 'no jargon' );
		$t->assert( false !== strpos( $html, 'page=epm-distribution#epm-dist-check' ) && false !== strpos( $html, 'Test feed and audio delivery' ), 'links to the delivery test' );

		wp_set_current_user( $make_user( 'contributor' ) );
		$html = \EPM\Readiness::render_html( $report );
		$t->assert( false === strpos( $html, 'epm-distribution' ) && false !== strpos( $html, 'delivers the audio' ), 'no link for users who cannot open the screen' );
		wp_set_current_user( $admin_id );
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
foreach ( array_unique( $users ) as $id ) {
	wp_delete_user( $id );
}
wp_set_current_user( 0 );

$t->finish();
