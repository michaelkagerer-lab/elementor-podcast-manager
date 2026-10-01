<?php
/**
 * Integration tests, executed inside WordPress:
 *
 *   wp eval-file tests/fixtures/seed.php
 *   wp eval-file tests/integration/run.php
 *
 * Exits non-zero when any test fails. Tests use the fixtures created by
 * tests/fixtures/seed.php (option epm_test_fixtures).
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\AudioMetadata;
use EPM\Categories;
use EPM\EpisodePostType;
use EPM\Episodes;
use EPM\Feed;
use EPM\PodcastSettings;
use EPM\Readiness;

require_once __DIR__ . '/lib.php';

$fx = get_option( 'epm_test_fixtures' );
if ( ! is_array( $fx ) || empty( $fx['ep1'] ) ) {
	WP_CLI::error( 'Run tests/fixtures/seed.php first.' );
}

// Capture _doing_it_wrong / deprecations raised by the plugin.
$GLOBALS['epm_test_doing_it_wrong'] = [];
add_action(
	'doing_it_wrong_run',
	static function ( $function, $message ) {
		$GLOBALS['epm_test_doing_it_wrong'][] = $function . ': ' . wp_strip_all_tags( (string) $message );
	},
	10,
	2
);

$t = new EPM_Test_Runner();

WP_CLI::log( 'Routing & capabilities' );

$t->test(
	'podcast feed rewrite rule is checked before the post type archive feed rule',
	static function ( EPM_Test_Runner $t ) {
		flush_rewrite_rules( false );
		$keys = array_keys( (array) get_option( 'rewrite_rules' ) );
		$feed = array_search( '^podcast/feed/?$', $keys, true );
		$cpt  = array_search( 'podcast/(feed|rdf|rss|rss2|atom)/?$', $keys, true );
		$t->assert( false !== $feed, 'feed rule missing' );
		$t->assert( false === $cpt || $feed < $cpt, "feed rule at $feed, post type feed rule at $cpt" );
	}
);

$t->test(
	'episode capabilities keep core post semantics and never break edit_posts',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$cap = get_post_type_object( EpisodePostType::CPT )->cap;
		$t->same( 'edit_post', $cap->edit_post, 'meta cap edit_post' );
		$t->same( 'publish_posts', $cap->publish_posts );

		$roles = [];
		foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $role ) {
			$login = 'epm_test_' . $role;
			$id    = username_exists( $login ) ?: wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
			( new WP_User( $id ) )->set_role( $role );
			$roles[ $role ] = (int) $id;
		}

		wp_set_current_user( $roles['administrator'] );
		$t->assert( current_user_can( 'edit_posts' ), 'admin edit_posts' );
		$t->assert( current_user_can( 'edit_post', $fx['ep1'] ), 'admin edits episode' );
		wp_set_current_user( $roles['contributor'] );
		$t->assert( current_user_can( 'edit_posts' ), 'contributor edit_posts' );
		$t->assert( ! current_user_can( $cap->publish_posts ), 'contributor cannot publish episodes' );
		$t->assert( ! current_user_can( 'edit_post', $fx['ep1'] ), 'contributor cannot edit others\' published episode' );
		wp_set_current_user( $roles['subscriber'] );
		$t->assert( ! current_user_can( 'edit_post', $fx['ep1'] ), 'subscriber cannot edit' );
		wp_set_current_user( 0 );
	}
);

$t->test(
	'a filtered custom capability governs every episode operation',
	static function ( EPM_Test_Runner $t ) {
		$filter = static function () {
			return 'manage_podcast_episodes';
		};
		add_filter( 'epm_cap_manage_episodes', $filter );
		EpisodePostType::register();
		$cap = get_post_type_object( EpisodePostType::CPT )->cap;
		$t->same( 'manage_podcast_episodes', $cap->edit_posts );
		$t->same( 'manage_podcast_episodes', $cap->publish_posts );
		$t->same( 'edit_post', $cap->edit_post, 'meta caps stay meta caps' );

		// A meta capability from the filter must not be registered as-is.
		remove_filter( 'epm_cap_manage_episodes', $filter );
		$bad = static function () {
			return 'edit_post';
		};
		add_filter( 'epm_cap_manage_episodes', $bad );
		EpisodePostType::register();
		$t->same( 'edit_posts', get_post_type_object( EpisodePostType::CPT )->cap->edit_posts );
		remove_filter( 'epm_cap_manage_episodes', $bad );
		EpisodePostType::register();
	}
);

WP_CLI::log( 'Episode data' );

$t->test(
	'episode meta keeps HTML in show notes/transcripts and line breaks in bios',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		update_post_meta( $fx['ep3'], '_epm_show_notes', '<ul><li><a href="https://example.com">A</a></li></ul><script>alert(1)</script>' );
		$notes = get_post_meta( $fx['ep3'], '_epm_show_notes', true );
		$t->assert( false !== strpos( $notes, '<ul><li><a href="https://example.com">A</a></li></ul>' ), 'list kept: ' . $notes );
		$t->assert( false === strpos( $notes, '<script' ), 'script stripped' );

		update_post_meta( $fx['ep3'], '_epm_guest_bio', "One\nTwo" );
		$t->same( "One\nTwo", get_post_meta( $fx['ep3'], '_epm_guest_bio', true ) );

		update_post_meta( $fx['ep3'], '_epm_season_number', '' );
		$t->same( '', get_post_meta( $fx['ep3'], '_epm_season_number', true ), 'empty season stays empty' );
		update_post_meta( $fx['ep3'], '_epm_season_number', 2 );
		Episodes::clear_data_cache( $fx['ep3'] );
	}
);

$t->test(
	'public data excludes draft, private, scheduled and password-protected episodes',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		wp_set_current_user( 0 );
		foreach ( [ 'draft', 'private', 'password', 'future' ] as $key ) {
			$t->same( null, epm()->episodes->get_public_data( $fx[ $key ] ), $key );
		}
		$t->assert( is_array( epm()->episodes->get_public_data( $fx['ep1'] ) ), 'published episode' );
	}
);

$t->test(
	'duration falls back to the audio file and is stored in seconds',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		delete_post_meta( $fx['ep3'], '_epm_duration' );
		Episodes::clear_data_cache( $fx['ep3'] );
		$data = epm()->episodes->get_data( $fx['ep3'] );
		$t->same( '1:02', $data['duration'] );
		$t->same( 62, $data['duration_seconds'] );
		$t->same( 62, Episodes::sync_duration_seconds( $fx['ep3'] ) );
	}
);

$t->test(
	'GUIDs are immutable across title, slug and domain changes',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$guid = Episodes::get_guid( $fx['ep2'] );
		$t->assert( 0 === strpos( $guid, 'urn:uuid:' ), 'new GUIDs are URNs' );
		wp_update_post( [ 'ID' => $fx['ep2'], 'post_title' => 'Episode Two (renamed)', 'post_name' => 'renamed-two' ] );
		$home = get_option( 'home' );
		update_option( 'home', 'https://moved.example.com' );
		Episodes::clear_data_cache( $fx['ep2'] );
		$t->same( $guid, epm()->episodes->get_data( $fx['ep2'] )['guid'] );
		update_option( 'home', $home );
		wp_update_post( [ 'ID' => $fx['ep2'], 'post_title' => 'Episode Two', 'post_name' => 'episode-two' ] );
		Episodes::clear_data_cache( $fx['ep2'] );
	}
);

$t->test(
	'latest episode for listeners skips episodes without audio',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$t->same( $fx['wav'], epm()->episodes->get_latest( true )->ID );
		$t->same( $fx['wav'], epm()->episodes->get_latest()->ID, 'newest overall is the WAV episode' );
		$none = epm()->episodes->get_latest( true );
		$t->assert( $fx['no_audio'] !== $none->ID, 'episode without audio never counts as latest' );
	}
);

$t->test(
	'pure helpers: durations, timestamps, languages, categories, UUIDv5',
	static function ( EPM_Test_Runner $t ) {
		foreach ( [ '' => true, '2892' => true, '48:12' => true, '1:02:03' => true, '48:61' => false, 'abc' => false, '1:2:3:4' => false ] as $in => $ok ) {
			$t->same( $ok, AudioMetadata::is_valid_duration( (string) $in ), "duration '$in'" );
		}
		foreach ( [ '48:12' => 2892, '1:02:03' => 3723, '95' => 95, '' => 0 ] as $in => $sec ) {
			$t->same( $sec, Episodes::timestamp_to_seconds( (string) $in ), "timestamp '$in'" );
		}
		foreach ( [ 'de_AT' => 'de-at', 'en_US' => 'en-us', 'de_DE_formal' => 'de-de', 'fr' => 'fr', 'bogus value' => 'en' ] as $in => $out ) {
			$t->same( $out, Feed::rss_language( $in ), "language '$in'" );
		}
		$t->same( [ 'Business', 'Marketing' ], Categories::decode( 'Business::Marketing' ) );
		$t->assert( Categories::is_valid_subcategory( 'Health & Fitness', 'Mental Health' ) );
		$t->assert( ! Categories::is_valid_subcategory( 'Business', 'Mental Health' ) );
		// Example from the Podcasting 2.0 podcast:guid specification.
		$t->same( '9b024349-ccf0-5f69-a609-6b82873eab3c', Feed::uuid_v5( Feed::GUID_NAMESPACE, 'podnews.net/rss' ) );
	}
);

$t->test(
	'settings sanitizer decodes category selections and rejects mismatched subcategories',
	static function ( EPM_Test_Runner $t ) {
		$clean = epm()->settings->sanitize( [ 'category' => 'Business::Marketing', 'title' => 'T', 'auto_embed' => '1' ] );
		$t->same( 'Business', $clean['category'] );
		$t->same( 'Marketing', $clean['subcategory'] );
		$t->same( true, $clean['auto_embed'] );
		$clean = epm()->settings->sanitize( [ 'category' => 'Business::Mental Health' ] );
		$t->same( '', $clean['subcategory'] );
		$clean = epm()->settings->sanitize( [ 'category' => 'Legacy Free Text' ] );
		$t->same( 'Legacy Free Text', $clean['category'], 'legacy values are kept' );
	}
);

WP_CLI::log( 'RSS feed' );

$t->test(
	'feed is well-formed and contains exactly the distributable public episodes',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$xp     = epm_test_xpath( epm_test_feed() );
		$titles = [];
		foreach ( $xp->query( '/rss/channel/item/title' ) as $node ) {
			$titles[] = $node->textContent;
		}
		$t->same( [ 'Episode Three (bonus)', 'Episode Two', 'Episode One: Hello & “Friends”' ], $titles, 'items (newest first, decoded titles)' );
		foreach ( $xp->query( '/rss/channel/item' ) as $item ) {
			$enclosure = $xp->query( 'enclosure', $item )->item( 0 );
			$t->assert( $enclosure && (int) $enclosure->getAttribute( 'length' ) > 0, 'enclosure length' );
			$t->same( 'audio/mpeg', $enclosure->getAttribute( 'type' ) );
			$t->assert( 1 === $xp->query( 'guid[@isPermaLink="false"]', $item )->length, 'guid' );
		}
	}
);

$t->test(
	'channel carries the tags directories require',
	static function ( EPM_Test_Runner $t ) {
		$xp = epm_test_xpath( epm_test_feed() );
		$q  = static function ( $path ) use ( $xp ) {
			$n = $xp->query( $path )->item( 0 );
			return $n ? trim( $n->textContent ) : null;
		};
		$t->same( 'Test & Talk Podcast', $q( '/rss/channel/title' ) );
		$t->same( 'A show about testing & tooling.', $q( '/rss/channel/description' ) );
		$t->same( 'de-at', $q( '/rss/channel/language' ) );
		$t->same( 'Jane Host', $q( '/rss/channel/itunes:author' ) );
		$t->same( 'jane@example.com', $q( '/rss/channel/itunes:owner/itunes:email' ) );
		$t->same( 'false', $q( '/rss/channel/itunes:explicit' ) );
		$t->same( 'episodic', $q( '/rss/channel/itunes:type' ) );
		$t->assert( null !== $q( '/rss/channel/lastBuildDate' ), 'lastBuildDate' );
		$t->assert( 1 === $xp->query( '/rss/channel/itunes:image/@href' )->length, 'itunes:image' );
		$t->assert( 1 === $xp->query( '/rss/channel/itunes:category[@text="Technology"]' )->length, 'category' );
		$t->assert( 1 === $xp->query( '/rss/channel/atom:link[@rel="self"]' )->length, 'atom self link' );
		$t->assert( (bool) preg_match( '/^[0-9a-f-]{36}$/', (string) $q( '/rss/channel/podcast:guid' ) ), 'podcast:guid' );
		$t->same( 'no', $q( '/rss/channel/podcast:locked' ) );
	}
);

$t->test(
	'items carry durations in seconds, numbers, types, explicit flags and rich notes',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$xp  = epm_test_xpath( epm_test_feed() );
		$ep1 = $xp->query( '/rss/channel/item[itunes:episode="1"]' )->item( 0 );
		$ep2 = $xp->query( '/rss/channel/item[itunes:episode="2"]' )->item( 0 );
		$ep3 = $xp->query( '/rss/channel/item[itunes:episode="3"]' )->item( 0 );
		$v   = static function ( $path, $ctx ) use ( $xp ) {
			$n = $xp->query( $path, $ctx )->item( 0 );
			return $n ? trim( $n->textContent ) : null;
		};
		$t->same( '95', $v( 'itunes:duration', $ep1 ) );
		$t->same( '1', $v( 'itunes:season', $ep1 ) );
		$t->same( 'Short summary of episode one.', $v( 'description', $ep1 ) );
		$html = (string) $v( 'content:encoded', $ep1 );
		$t->assert( false !== strpos( $html, '<a href="https://example.com">a link</a>' ), 'description HTML' );
		$t->assert( (bool) preg_match( '#<ul>\s*<li><a href="https://example.com">Example</a></li>\s*</ul>#', $html ), 'show notes HTML: ' . $html );
		$t->assert( 1 === $xp->query( 'podcast:chapters[@type="application/json+chapters"]', $ep1 )->length, 'chapters tag' );
		$t->assert( 1 === $xp->query( 'podcast:transcript[@type="text/html"]', $ep1 )->length, 'transcript tag' );
		$t->assert( 1 === $xp->query( 'itunes:image', $ep1 )->length, 'square episode artwork' );
		$t->same( 'true', $v( 'itunes:explicit', $ep2 ), 'per-episode explicit' );
		$t->same( 0, $xp->query( 'itunes:image', $ep2 )->length, 'non-square featured image is not episode artwork' );
		$t->same( 'bonus', $v( 'itunes:episodeType', $ep3 ) );
		$t->same( '62', $v( 'itunes:duration', $ep3 ) );
	}
);

$t->test(
	'feed window is applied after eligibility filtering; serial feeds are oldest-first',
	static function ( EPM_Test_Runner $t ) {
		epm_test_with_settings(
			[ 'feed_limit' => 1 ],
			static function () use ( $t ) {
				$xp = epm_test_xpath( epm_test_feed() );
				$t->same( 1, $xp->query( '/rss/channel/item' )->length );
				$t->same( 'Episode Three (bonus)', $xp->query( '/rss/channel/item/title' )->item( 0 )->textContent, 'newest eligible, not the newer WAV/no-audio episodes' );
			}
		);
		epm_test_with_settings(
			[ 'type' => 'serial' ],
			static function () use ( $t ) {
				$xp = epm_test_xpath( epm_test_feed() );
				$t->same( 'serial', $xp->query( '/rss/channel/itunes:type' )->item( 0 )->textContent );
				$t->same( '1', $xp->query( '/rss/channel/item/itunes:episode' )->item( 0 )->textContent, 'oldest first' );
			}
		);
		// A limit keeps the newest episodes of a serial show too (new
		// episodes must reach the feed), still oldest first.
		epm_test_with_settings(
			[
				'type'       => 'serial',
				'feed_limit' => 2,
			],
			static function () use ( $t ) {
				$xp     = epm_test_xpath( epm_test_feed() );
				$titles = [];
				foreach ( $xp->query( '/rss/channel/item/title' ) as $node ) {
					$titles[] = $node->textContent;
				}
				$t->same( [ 'Episode Two', 'Episode Three (bonus)' ], $titles, 'serial with a limit: the newest two, oldest first' );
			}
		);
	}
);

$t->test(
	'distribution options: subcategory, locked, funding, new feed URL, block, complete',
	static function ( EPM_Test_Runner $t ) {
		epm_test_with_settings(
			[
				'category'      => 'Business',
				'subcategory'   => 'Marketing',
				'locked'        => true,
				'funding_url'   => 'https://example.com/support',
				'funding_label' => 'Support us',
				'new_feed_url'  => 'https://new.example.com/feed/',
				'itunes_block'  => true,
				'complete'      => true,
				'explicit'      => 'explicit',
			],
			static function () use ( $t ) {
				$xp = epm_test_xpath( epm_test_feed() );
				$t->assert( 1 === $xp->query( '/rss/channel/itunes:category[@text="Business"]/itunes:category[@text="Marketing"]' )->length, 'nested category' );
				$t->same( 'yes', $xp->query( '/rss/channel/podcast:locked' )->item( 0 )->textContent );
				$t->same( 'Support us', $xp->query( '/rss/channel/podcast:funding[@url="https://example.com/support"]' )->item( 0 )->textContent );
				$t->same( 'https://new.example.com/feed/', $xp->query( '/rss/channel/itunes:new-feed-url' )->item( 0 )->textContent );
				$t->same( 'Yes', $xp->query( '/rss/channel/itunes:block' )->item( 0 )->textContent );
				$t->same( 'Yes', $xp->query( '/rss/channel/itunes:complete' )->item( 0 )->textContent );
				$t->same( 'true', $xp->query( '/rss/channel/itunes:explicit' )->item( 0 )->textContent );
			}
		);
	}
);

$t->test(
	'feed cache is reused and invalidated by episode changes',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		Feed::flush_cache();
		$first = epm()->feed->get_document();
		$t->assert( false !== get_transient( 'epm_feed_cache' ), 'cached' );
		$t->same( $first['etag'], epm()->feed->get_document()['etag'], 'stable etag' );
		update_post_meta( $fx['ep1'], '_edit_lock', time() . ':1' );
		$t->assert( false !== get_transient( 'epm_feed_cache' ), 'editor heartbeats keep the cache' );
		wp_update_post( [ 'ID' => $fx['ep3'], 'post_excerpt' => 'changed' ] );
		$t->same( false, get_transient( 'epm_feed_cache' ), 'episode save flushes the cache' );
		wp_update_post( [ 'ID' => $fx['ep3'], 'post_excerpt' => '' ] );
	}
);

WP_CLI::log( 'Rendering' );

$t->test(
	'shortcodes render public episodes and nothing for restricted ones',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		wp_set_current_user( 0 );
		$t->assert( false !== strpos( do_shortcode( '[podcast_player id="' . $fx['ep1'] . '"]' ), 'data-epm-player' ), 'player' );
		foreach ( [ 'draft', 'private', 'password', 'future' ] as $key ) {
			$t->same( '', do_shortcode( '[podcast_player id="' . $fx[ $key ] . '"]' ), $key . ' player' );
			$t->same( '', do_shortcode( '[podcast_transcript id="' . $fx[ $key ] . '"]' ), $key . ' transcript' );
		}
		$list = do_shortcode( '[podcast_episodes limit="20"]' );
		foreach ( [ 'Draft Secret', 'Private Secret', 'Password Secret', 'Future Secret' ] as $secret ) {
			$t->assert( false === strpos( $list, $secret ), $secret . ' in list' );
		}
		$t->assert( false !== strpos( do_shortcode( '[podcast_subscribe]' ), 'epm-subscribe__item--spotify' ), 'subscribe' );
		$t->assert( false !== strpos( do_shortcode( '[podcast_chapters id="' . $fx['ep1'] . '"]' ), 'data-epm-seek="30"' ), 'chapters' );
	}
);

$t->test(
	'episode pages get the player and details automatically, once',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		wp_set_current_user( 0 );
		$GLOBALS['wp_query']     = new WP_Query( [ 'p' => $fx['ep1'], 'post_type' => EpisodePostType::CPT ] );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp_query']->the_post();
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $fx['ep1'] ) );
		$t->assert( 1 === substr_count( $html, 'data-epm-player' ), 'one player' );
		foreach ( [ 'epm-guest', 'epm-show-notes', 'epm-chapters', 'epm-transcript', 'First episode with' ] as $needle ) {
			$t->assert( false !== strpos( $html, $needle ), $needle );
		}
		$again = apply_filters( 'the_content', get_post_field( 'post_content', $fx['ep1'] ) );
		$t->assert( false === strpos( $again, 'data-epm-player' ), 'no second embed on repeated the_content' );

		epm_test_with_settings(
			[ 'auto_embed' => false ],
			static function () use ( $t, $fx ) {
				$fresh = new EPM\EpisodeTemplate();
				$html  = $fresh->filter_content( 'plain' );
				$t->same( 'plain', $html, 'setting off' );
			}
		);
		wp_reset_postdata();
		$GLOBALS['wp_query']     = new WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	}
);

$t->test(
	'readiness lists problems first and flags WAV-only and audio-less episodes',
	static function ( EPM_Test_Runner $t ) {
		$report   = Readiness::report();
		$statuses = array_column( $report['checks'], 'status' );
		$sorted   = $statuses;
		usort(
			$sorted,
			static function ( $a, $b ) {
				$rank = [ 'error' => 0, 'warning' => 1, 'ok' => 2 ];
				return $rank[ $a ] <=> $rank[ $b ];
			}
		);
		$t->same( $sorted, $statuses, 'severity order' );
		$labels = implode( ' | ', array_column( $report['checks'], 'label' ) );
		$t->assert( false !== strpos( $labels, 'WAV only' ), 'WAV warning' );
		$t->assert( false !== strpos( $labels, 'No Audio Yet' ), 'no-audio warning' );
		$t->assert( $report['ready'], 'fixtures are distribution-ready' );
	}
);

$t->test(
	'readiness links problems to the screen that fixes them, only for users who can open it',
	static function ( EPM_Test_Runner $t ) {
		$stored  = get_option( PodcastSettings::OPTION );
		$changed = is_array( $stored ) ? $stored : [];

		$changed['owner_email'] = '';
		update_option( PodcastSettings::OPTION, $changed );

		$find = static function ( array $report, string $label ): ?array {
			foreach ( $report['checks'] as $check ) {
				if ( $label === $check['label'] ) {
					return $check;
				}
			}
			return null;
		};

		$admin = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);
		wp_set_current_user( (int) $admin[0] );
		$check = $find( Readiness::report(), 'Owner email' );
		$t->assert( null !== $check && 'error' === $check['status'], 'missing owner email is an error' );
		$t->assert( false !== strpos( (string) $check['url'], 'page=epm-settings#epm-s-owner-email' ), 'admin gets the settings link' );
		$t->assert( false !== strpos( Readiness::render_html(), 'epm-s-owner-email' ), 'link rendered' );

		$editor = username_exists( 'epm_test_editor' );
		if ( $editor ) {
			wp_set_current_user( (int) $editor );
			$check = $find( Readiness::report(), 'Owner email' );
			$t->same( '', $check['url'] ?? null, 'no link to a screen the editor cannot open' );
		}

		wp_set_current_user( 0 );
		update_option( PodcastSettings::OPTION, $stored );
	}
);

$t->test(
	'the latest-episode CTA shortcode loads the stylesheet but not the player script',
	static function ( EPM_Test_Runner $t ) {
		$option  = \EPM\PodcastSettings::OPTION;
		$enabled = static function ( $value ) {
			$value                       = is_array( $value ) ? $value : [];
			$value['latest_cta_enabled'] = true;
			return $value;
		};
		wp_dequeue_script( 'epm-player' );
		wp_dequeue_style( 'epm-frontend' );
		// Disabled (the default): nothing renders, nothing loads (WID-N9).
		do_shortcode( '[podcast_latest_cta]' );
		$t->assert( ! wp_style_is( 'epm-frontend', 'enqueued' ), 'disabled: no stylesheet' );
		add_filter( 'option_' . $option, $enabled );
		add_filter( 'default_option_' . $option, $enabled );
		try {
			$t->assert( '' !== do_shortcode( '[podcast_latest_cta]' ), 'enabled: the button renders' );
			$t->assert( wp_style_is( 'epm-frontend', 'enqueued' ), 'stylesheet enqueued' );
			$t->assert( ! wp_script_is( 'epm-player', 'enqueued' ), 'player script not enqueued' );
		} finally {
			remove_filter( 'option_' . $option, $enabled );
			remove_filter( 'default_option_' . $option, $enabled );
		}
	}
);

if ( defined( 'ELEMENTOR_VERSION' ) ) {
	WP_CLI::log( 'Elementor' );

	$t->test(
		'all podcast widgets register without duplicate control IDs and opt out of element caching',
		static function ( EPM_Test_Runner $t ) {
			$types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
			$ours  = array_filter(
				array_keys( $types ),
				static function ( $name ) {
					return 0 === strpos( $name, 'epm-' );
				}
			);
			// 11 widgets + Episode Video (1.3.0).
			$t->same( 12, count( $ours ), 'widget count' );

			$GLOBALS['epm_test_doing_it_wrong'] = [];
			foreach ( $ours as $name ) {
				$widget   = $types[ $name ];
				$controls = $widget->get_controls();
				$t->assert( isset( $controls['style_source'] ) || in_array( $name, [], true ), $name . ' style_source' );
				$method = new ReflectionMethod( $widget, 'is_dynamic_content' );
				$method->setAccessible( true );
				$t->assert( true === $method->invoke( $widget ), $name . ' is dynamic content' );
			}
			$t->same( [], $GLOBALS['epm_test_doing_it_wrong'], 'no _doing_it_wrong while building controls' );
		}
	);
}

$t->test(
	'no plugin-triggered _doing_it_wrong notices during the run',
	static function ( EPM_Test_Runner $t ) {
		$t->same( [], $GLOBALS['epm_test_doing_it_wrong'] );
	}
);

$t->finish();
