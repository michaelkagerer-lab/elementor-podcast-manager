<?php
/**
 * Integration tests for "Details shown by default" and the design data
 * around it (DESIGN-01, DESIGN-N1, DESIGN-N3, DESIGN-N4):
 *
 * - an untouched site renders exactly what 1.3.0 rendered
 *   (tests/fixtures/details-1.3.0.json);
 * - presets visibly change the details of every consumer that inherits
 *   (new widgets, shortcodes without attributes, the automatic episode
 *   page); explicit local choices beat them; "Default" follows them again;
 * - widgets saved by 1.3.0 (tests/fixtures/elementor-1.3.0.json, saved
 *   with the real 1.3.0 editor) never change with the site defaults, and
 *   open in the editor with their 1.3.0 values made explicit;
 * - the preset maps stored by 1.1–1.3 become suggestions, never active
 *   values; accepting and dismissing them; design export/import (format 2,
 *   format-1 files into the suggestions);
 * - the Design screen preview renders what the frontend renders;
 * - an explicit layout choice is stored even when it equals the default;
 * - the preset "layout" key and the unused preset import/export.
 *
 *   wp eval-file tests/integration/design.php
 *
 * Every test removes the design option again (the seeded site has none).
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Admin;
use EPM\DesignSettings;

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

$t   = new EPM_Test_Runner();
$ep1 = (int) $fx['ep1'];

// The Design screen needs a user who may manage the podcast.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $admin[0] ?? 1 ) );

/**
 * Back to a site without design settings.
 */
$reset = static function (): void {
	delete_option( DesignSettings::OPTION );
};
$reset();

$t->test(
	'UX-N4: repeating the setup design step preserves manual design and detail choices',
	static function ( EPM_Test_Runner $t ) use ( $reset ) {
		try {
			epm()->design->apply_preset( 'business-tuning' );
			$settings = epm()->design->all();
			$settings['accent'] = '#123456';
			$settings['details']['player']['show_volume'] = true;
			update_option( DesignSettings::OPTION, $settings );
			( new EPM\AdminPages() )->save_step( 'design', [ 'preset' => 'business-tuning' ] );
			$t->same( $settings, epm()->design->all(), 'revisiting the same preset keeps all custom values' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'SEC-N10: design permission denials return 403 and nested flags are rejected without warnings',
	static function ( EPM_Test_Runner $t ) {
		$user = get_current_user_id();
		$die = static function () {
			return static function ( $message, $title, $args ) {
				throw new RuntimeException( 'denied', (int) ( $args['response'] ?? 500 ) );
			};
		};
		add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		wp_set_current_user( 0 );
		try {
			foreach ( [ 'handle_design_export', 'handle_design_import', 'handle_design_preset', 'handle_design_details' ] as $method ) {
				try {
					( new Admin() )->$method();
					$t->assert( false, $method . ' must refuse anonymous access' );
				} catch ( RuntimeException $error ) {
					$t->same( 403, $error->getCode(), $method . ' returns a permission status' );
				}
			}
		} finally {
			wp_set_current_user( $user );
			remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		}
		$warnings = [];
		set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
			$warnings[] = $message;
			return true;
		} );
		try {
			$flags = epm()->design->sanitize_flag_map( [ 'show_title' => [ 'nested' => true ], 'show_date' => 'yes' ] );
		} finally {
			restore_error_handler();
		}
		$t->same( [], $warnings, 'nested import flags emit no PHP warning' );
		$t->same( [ 'show_title' => false, 'show_date' => true ], $flags, 'malformed flags are false, valid values survive' );
	}
);

/**
 * Visible parts of the first player in some markup ('' when none).
 */
$player = static function ( string $html ): array {
	foreach ( epm_test_signature( $html ) as $component ) {
		if ( 'player' === $component['type'] ) {
			return $component;
		}
	}
	return [ 'type' => '', 'layout' => '', 'parts' => [], 'meta' => [], 'label' => '', 'sticky' => '' ];
};
$shows  = static function ( string $html, string $part ) use ( $player ): bool {
	$p = $player( $html );
	return in_array( $part, $p['parts'], true ) || in_array( $part, $p['meta'], true );
};

/**
 * Widget settings of the legacy fixture (1.3.0 editor save), by element ID.
 */
$legacy = static function () use ( $ep1 ): array {
	$json = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/elementor-1.3.0.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$out  = [];
	foreach ( (array) json_decode( str_replace( '%ep1%', (string) $ep1, $json ), true ) as $widget ) {
		$out[ $widget['id'] ] = $widget;
	}
	return $out;
};

$new_player = static function ( array $settings = [] ) use ( $ep1 ): string {
	return epm_test_widget( 'epm-podcast-player', array_merge( [ 'epm_schema' => '2', 'source' => 'specific', 'episode_id' => $ep1 ], $settings ) );
};

WP_CLI::log( 'An untouched site renders what 1.3.0 rendered' );

$t->test(
	'Design screen has no duplicate HTML IDs',
	static function ( EPM_Test_Runner $t ) {
		ob_start();
		( new Admin() )->render_design();
		$html = (string) ob_get_clean();
		foreach ( [ '_epm_preset_nonce', '_wpnonce', '_epm_export_nonce', '_epm_import_nonce' ] as $nonce_name ) {
			$t->assert( (bool) preg_match( '/name="' . preg_quote( $nonce_name, '/' ) . '"[^>]*value="[^"]+"/', $html ), $nonce_name . ' is rendered for form submission' );
		}
		preg_match_all( '~\\s+id=["\']([^"\']+)["\']~', $html, $matches );
		$counts = array_count_values( $matches[1] );
		$duplicates = array_keys( array_filter( $counts, static fn ( int $count ): bool => $count > 1 ) );
		$t->same( [], $duplicates, 'all IDs are unique' );
	}
);

$t->test(
	'every consumer shows the same details as 1.3.0 (normalized markup)',
	static function ( EPM_Test_Runner $t ) use ( $fx ) {
		$expected = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/details-1.3.0.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$t->assert( is_array( $expected ) && count( $expected ) > 20, 'snapshot loaded' );
		foreach ( epm_test_details_consumers( $fx ) as $name => $render ) {
			$t->same( $expected[ $name ] ?? null, epm_test_signature( $render() ), $name );
		}
	}
);

WP_CLI::log( 'Presets change the details of everything that inherits (DESIGN-01)' );

$t->test(
	'Business Tuning hides the volume slider and shows description, number and date',
	static function ( EPM_Test_Runner $t ) use ( $reset, $shows, $player, $new_player, $ep1 ) {
		try {
			epm()->design->apply_preset( 'business-tuning' );
			$consumers = [
				'new Player widget'      => $new_player(),
				'[podcast_player id]'    => do_shortcode( '[podcast_player id="' . $ep1 . '"]' ),
				'[podcast_latest]'       => do_shortcode( '[podcast_latest]' ),
				'new Latest widget'      => epm_test_widget( 'epm-latest-episode', [ 'epm_schema' => '2' ] ),
				'automatic episode page' => epm_test_episode_page( $ep1 ),
			];
			foreach ( $consumers as $name => $html ) {
				$t->assert( 'player' === $player( $html )['type'], $name . ' renders a player' );
				$t->assert( ! $shows( $html, 'volume' ), $name . ' hides the volume slider' );
			}
			$t->assert( $shows( $consumers['new Player widget'], 'description' ), 'Player widget shows the description' );
			$t->assert( $shows( $consumers['[podcast_player id]'], 'description' ), '[podcast_player] shows the description' );
			$t->assert( $shows( $consumers['new Player widget'], 'date' ), 'Player widget shows the date' );
			$t->same( '01 / Episode', $player( $consumers['new Player widget'] )['label'], 'Player widget shows the number in the label' );
			$t->assert( $shows( $consumers['automatic episode page'], 'download' ), 'the episode page keeps its download button' );
			$t->assert( ! $shows( $consumers['automatic episode page'], 'description' ), 'the episode page body already is the description' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'Minimal hides artwork in players, cards and on the episode page',
	static function ( EPM_Test_Runner $t ) use ( $reset, $shows, $new_player, $ep1 ) {
		try {
			epm()->design->apply_preset( 'minimal' );
			$t->assert( ! $shows( $new_player( [ 'layout' => 'artwork' ] ), 'artwork' ), 'new Player widget, Artwork layout' );
			$t->assert( ! $shows( do_shortcode( '[podcast_player id="' . $ep1 . '" layout="full"]' ), 'artwork' ), '[podcast_player layout=full]' );
			$t->assert( ! $shows( epm_test_episode_page( $ep1 ), 'artwork' ), 'automatic episode page' );
			$cards = epm_test_signature( do_shortcode( '[podcast_episodes limit="3" layout="cards"]' ) );
			$t->assert( ! empty( $cards[0]['items'] ), 'cards rendered' );
			foreach ( $cards[0]['items'] ?? [] as $item ) {
				$t->assert( false === strpos( $item, 'artwork' ), '[podcast_episodes layout=cards] card without artwork: ' . $item );
			}
			$list = epm_test_signature( epm_test_widget( 'epm-episode-list', [ 'epm_schema' => '2', 'number' => 3, 'layout' => 'grid' ] ) );
			foreach ( $list[0]['items'] ?? [ 'none' ] as $item ) {
				$t->assert( false === strpos( $item, 'artwork' ), 'new Episode List widget, grid: ' . $item );
			}
			// Minimal sets nothing else: the shortcode keeps its own date default.
			$t->assert( ! $shows( do_shortcode( '[podcast_latest]' ), 'date' ), '[podcast_latest] keeps the date hidden' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'two presets give the same new widget different details; Neutral returns to the built-in defaults',
	static function ( EPM_Test_Runner $t ) use ( $reset, $player, $new_player, $fx ) {
		try {
			epm()->design->apply_preset( 'business-tuning' );
			$business = $player( $new_player( [ 'layout' => 'full' ] ) );
			epm()->design->apply_preset( 'minimal' );
			$minimal = $player( $new_player( [ 'layout' => 'full' ] ) );
			$t->assert( $business['parts'] !== $minimal['parts'] || $business['meta'] !== $minimal['meta'], 'different details: ' . wp_json_encode( [ $business, $minimal ] ) );

			epm()->design->apply_preset( 'neutral' );
			$expected = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/details-1.3.0.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( epm_test_details_consumers( $fx ) as $name => $render ) {
				$t->same( $expected[ $name ], epm_test_signature( $render() ), 'Neutral: ' . $name );
			}
		} finally {
			$reset();
		}
	}
);

$t->test(
	'explicit local values beat the preset; "Default" follows it',
	static function ( EPM_Test_Runner $t ) use ( $reset, $shows, $new_player, $ep1 ) {
		try {
			epm()->design->apply_preset( 'business-tuning' );
			$t->assert( $shows( $new_player( [ 'show_volume' => 'yes' ] ), 'volume' ), 'widget Show beats the preset' );
			$t->assert( ! $shows( $new_player( [ 'show_description' => 'no' ] ), 'description' ), 'widget Hide beats the preset' );
			$t->assert( ! $shows( $new_player( [ 'show_volume' => '' ] ), 'volume' ), 'widget Default follows the preset' );
			$t->assert( $shows( do_shortcode( '[podcast_player id="' . $ep1 . '" show_volume="yes"]' ), 'volume' ), 'shortcode attribute yes beats the preset' );
			$t->assert( ! $shows( do_shortcode( '[podcast_player id="' . $ep1 . '" show_description="no"]' ), 'description' ), 'shortcode attribute no beats the preset' );
			$t->assert( $shows( do_shortcode( '[podcast_latest show_volume="yes"]' ), 'volume' ), '[podcast_latest] attribute' );

			epm()->design->apply_preset( 'minimal' );
			$t->assert( $shows( $new_player( [ 'layout' => 'full', 'show_artwork' => 'yes' ] ), 'artwork' ), 'widget Show keeps artwork under Minimal' );
			$cards = epm_test_signature( do_shortcode( '[podcast_episodes limit="2" layout="cards" show_artwork="yes"]' ) );
			$t->assert( false !== strpos( (string) ( $cards[0]['items'][0] ?? '' ), 'artwork' ), 'shortcode show_artwork="yes" keeps card artwork' );

			// Back to the built-in defaults: "Default" follows again.
			epm()->design->apply_preset( 'neutral' );
			$t->assert( $shows( $new_player( [ 'show_volume' => '' ] ), 'volume' ), 'Default follows Neutral' );
			$t->assert( $shows( $new_player( [ 'layout' => 'full' ] ), 'artwork' ), 'artwork back with Neutral' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'the site details defaults are their own setting: saving colors never changes them',
	static function ( EPM_Test_Runner $t ) use ( $reset, $shows, $new_player ) {
		try {
			epm()->design->update_details( [ 'player' => [ 'show_date' => true, 'show_volume' => false ] ] );
			$t->assert( $shows( $new_player(), 'date' ) && ! $shows( $new_player(), 'volume' ), 'saved details apply to new widgets' );

			$tokens           = epm()->design->all();
			$tokens['accent'] = '#123456';
			update_option( DesignSettings::OPTION, epm()->design->sanitize( $tokens ) );
			$t->same( '#123456', epm()->design->get( 'accent' ), 'color saved' );
			$t->assert( $shows( $new_player(), 'date' ) && ! $shows( $new_player(), 'volume' ), 'details kept after a color save' );

			// A form save stores what differs from the built-in default
			// (or was set before) and leaves the rest to each consumer.
			epm()->design->update_details( [ 'latest' => [ 'show_date' => true ] ], true );
			$t->same( [], epm()->design->get( 'details' )['latest'] ?? [], 'an unchanged checkbox stores nothing' );
			$t->assert( ! $shows( do_shortcode( '[podcast_latest]' ), 'date' ), '[podcast_latest] keeps its own date default' );
			epm()->design->update_details( [ 'latest' => [ 'show_date' => false ] ], true );
			epm()->design->update_details( [ 'latest' => [ 'show_date' => true ] ], true );
			$t->same( true, epm()->design->get( 'details' )['latest']['show_date'] ?? null, 'a value set before stays set' );
			$t->assert( $shows( do_shortcode( '[podcast_latest]' ), 'date' ), 'and now the shortcode follows it' );

			epm()->design->update_details( [ 'nope' => [ 'show_date' => true ], 'player' => [ 'show_bogus' => true, 'show_title' => 'no' ] ] );
			$details = epm()->design->get( 'details' );
			$t->assert( ! isset( $details['nope'] ) && ! isset( $details['player']['show_bogus'] ), 'unknown contexts and details dropped' );
			$t->same( false, $details['player']['show_title'] ?? null, '"no" is false' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'the automatic episode page keeps Full for Minimal/Compact unless its details hide every control those layouts hide',
	static function ( EPM_Test_Runner $t ) use ( $reset, $player, $ep1 ) {
		try {
			$t->same( 'full', $player( epm_test_episode_page( $ep1 ) )['layout'], 'Minimal design: Full on the episode page' );
			epm()->design->update_details(
				[
					'episode_page' => [
						'show_playback_speed' => false,
						'show_volume'         => false,
						'show_download'       => false,
						'show_share'          => false,
						'show_description'    => false,
					],
				]
			);
			$t->same( 'minimal', $player( epm_test_episode_page( $ep1 ) )['layout'], 'nothing left to show: the chosen layout stays' );

			// The filter stays the final word, after the details.
			$filter = static function ( $args ) {
				$args['show_volume'] = true;
				$args['layout']      = 'artwork';
				return $args;
			};
			add_filter( 'epm_auto_embed_player_args', $filter );
			$html = epm_test_episode_page( $ep1 );
			remove_filter( 'epm_auto_embed_player_args', $filter );
			$t->same( 'artwork', $player( $html )['layout'], 'filter layout wins' );
			$t->assert( in_array( 'volume', $player( $html )['parts'], true ), 'filter flag wins' );
		} finally {
			$reset();
		}
	}
);

WP_CLI::log( 'Widgets saved by 1.3.0 keep their details' );

$t->test(
	'the legacy fixture renders the same before and after preset and details changes',
	static function ( EPM_Test_Runner $t ) use ( $reset, $legacy ) {
		$render = static function () use ( $legacy ): array {
			$out = [];
			foreach ( $legacy() as $id => $widget ) {
				$sig = epm_test_signature( epm_test_widget( $widget['widgetType'], $widget['settings'] ) );
				// Player B never stored a layout (1.3.0 dropped "Minimal" as
				// equal to the default): it follows Podcast → Design, as before.
				if ( 'p130002' === $id ) {
					unset( $sig[0]['layout'] );
				}
				$out[ $id ] = $sig;
			}
			return $out;
		};
		try {
			$before = $render();
			$t->same( 'full', $before['p130001'][0]['layout'] ?? '', 'explicit layout' );
			$t->assert( ! in_array( 'volume', $before['p130001'][0]['parts'], true ) && in_array( 'download', $before['p130001'][0]['parts'], true ), 'switched off/on in 1.3.0' );
			$t->assert( in_array( 'date', $before['p130001'][0]['meta'], true ), 'date switched on' );
			$t->assert( in_array( 'description', $before['p130002'][0]['parts'], true ) && ! in_array( 'share', $before['p130002'][0]['parts'], true ), 'player B' );

			foreach ( [ 'business-tuning', 'minimal', 'soft-voice', 'neutral' ] as $preset ) {
				epm()->design->apply_preset( $preset );
				$t->same( $before, $render(), 'unchanged after applying ' . $preset );
			}
			epm()->design->update_details(
				[
					'player' => [ 'show_volume' => true, 'show_artwork' => false, 'show_share' => true, 'show_episode_number' => true ],
					'latest' => [ 'show_description' => true, 'show_guest' => true, 'show_volume' => false ],
					'list'   => [ 'show_excerpt' => true, 'show_episode_number' => true, 'show_artwork' => false ],
				]
			);
			$t->same( $before, $render(), 'unchanged after changing the details defaults' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'a legacy widget opens in the editor with its 1.3.0 values made explicit (and renders the same)',
	static function ( EPM_Test_Runner $t ) use ( $reset, $legacy ) {
		try {
			epm()->design->apply_preset( 'business-tuning' );
			foreach ( $legacy() as $id => $widget ) {
				$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
					[
						'id'         => $id,
						'elType'     => 'widget',
						'widgetType' => $widget['widgetType'],
						'settings'   => $widget['settings'],
						'elements'   => [],
					]
				);
				$raw = $element->get_raw_data();
				$t->same( '2', $raw['settings']['epm_schema'] ?? null, $id . ': schema marker' );
				$t->same(
					epm_test_signature( epm_test_widget( $widget['widgetType'], $widget['settings'] ) ),
					epm_test_signature( epm_test_widget( $widget['widgetType'], $raw['settings'] ) ),
					$id . ': converted settings render the same'
				);
				// Converting twice changes nothing.
				$again = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
					[
						'id'         => $id,
						'elType'     => 'widget',
						'widgetType' => $widget['widgetType'],
						'settings'   => $raw['settings'],
						'elements'   => [],
					]
				)->get_raw_data();
				$t->same( $raw['settings'], $again['settings'], $id . ': idempotent' );
			}
			$raw = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
				[
					'id'         => 'p130001',
					'elType'     => 'widget',
					'widgetType' => 'epm-podcast-player',
					'settings'   => $legacy()['p130001']['settings'],
					'elements'   => [],
				]
			)->get_raw_data()['settings'];
			$t->same( 'no', $raw['show_volume'] ?? null, 'stored "" (switched off) becomes Hide' );
			$t->same( 'yes', $raw['show_date'] ?? null, 'stored yes stays Show' );
			$t->same( 'yes', $raw['show_artwork'] ?? null, 'absent default-on becomes Show' );
			$t->same( 'no', $raw['show_description'] ?? null, 'absent default-off becomes Hide' );
			$t->same( 'full', $raw['layout'] ?? null, 'layout kept' );
		} finally {
			$reset();
		}
	}
);

WP_CLI::log( 'Preset maps stored by 1.1–1.3 become suggestions' );

/**
 * The option as 1.3.0's apply_preset( 'business-tuning' ) stored it.
 */
$stored_130 = static function (): array {
	$tokens = array_merge( DesignSettings::defaults(), (array) epm()->presets->get( 'business-tuning' )['tokens'] );
	foreach ( [ 'details', 'details_suggested', 'details_version' ] as $key ) {
		unset( $tokens[ $key ] );
	}
	$tokens['preset']              = 'business-tuning';
	$tokens['preset_visibility']   = [
		'show_artwork'        => true,
		'show_episode_label'  => true,
		'show_title'          => true,
		'show_episode_number' => true,
		'show_season'         => false,
		'show_guest'          => true,
		'show_description'    => true,
		'show_date'           => true,
		'show_duration'       => true,
	];
	$tokens['preset_player']       = [
		'show_playback_speed' => true,
		'show_skip_backward'  => true,
		'show_skip_forward'   => true,
		'show_volume'         => false,
		'show_download'       => false,
	];
	$tokens['preset_episode_list'] = [];
	return $tokens;
};

$t->test(
	'upgrading keeps the stored maps inactive: same rendering, offered as suggestions',
	static function ( EPM_Test_Runner $t ) use ( $reset, $stored_130, $fx, $shows, $new_player ) {
		try {
			update_option( DesignSettings::OPTION, $stored_130() );
			$render = static function () use ( $fx ): array {
				$out = [];
				foreach ( epm_test_details_consumers( $fx ) as $name => $fn ) {
					$out[ $name ] = epm_test_signature( $fn() );
				}
				return $out;
			};
			$before = $render();
			epm()->design->maybe_migrate();
			$stored = get_option( DesignSettings::OPTION );
			$t->same( $before, $render(), 'nothing changes on the site' );
			$t->same( [], (array) ( $stored['details'] ?? [] ), 'no active details' );
			$t->assert( ! empty( $stored['details_suggested'] ), 'suggestions stored' );
			$t->same( false, $stored['details_suggested']['player']['show_volume'] ?? null, 'suggestion: player volume hidden' );
			$t->same( $stored_130()['preset_player'], $stored['preset_player'] ?? null, 'the 1.3.0 map is kept as it was (rollback)' );
			$t->assert( ! empty( $stored['details_version'] ), 'migrated once' );

			$changes = \EPM\Details::suggested_changes();
			$t->assert( ! empty( $changes ), 'the Design screen can list what would change' );
			$found = false;
			foreach ( $changes as $change ) {
				if ( 'player' === $change['context'] && 'show_volume' === $change['flag'] ) {
					$found = true;
					$t->same( [ true, false ], [ $change['now'], $change['suggested'] ], 'volume: shown now, hidden suggested' );
				}
			}
			$t->assert( $found, 'volume change listed' );

			// Running it again does nothing.
			epm()->design->maybe_migrate();
			$t->same( $stored, get_option( DesignSettings::OPTION ), 'idempotent' );

			epm()->design->apply_suggested_details();
			$t->assert( ! $shows( $new_player(), 'volume' ), 'accepted: new widgets hide the volume' );
			$t->same( [], (array) ( get_option( DesignSettings::OPTION )['details_suggested'] ?? [] ), 'suggestions cleared' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'dismissing suggestions changes nothing on the site',
	static function ( EPM_Test_Runner $t ) use ( $reset, $stored_130, $shows, $new_player ) {
		try {
			update_option( DesignSettings::OPTION, $stored_130() );
			epm()->design->maybe_migrate();
			epm()->design->dismiss_suggested_details();
			$stored = get_option( DesignSettings::OPTION );
			$t->same( [], (array) ( $stored['details_suggested'] ?? [] ), 'cleared' );
			$t->same( [], (array) ( $stored['details'] ?? [] ), 'nothing active' );
			$t->assert( $shows( $new_player(), 'volume' ), 'volume still shown' );
			$t->same( [], \EPM\Details::suggested_changes(), 'nothing offered' );
		} finally {
			$reset();
		}
	}
);

WP_CLI::log( 'Design export and import' );

$t->test(
	'export (format 2) and import round trip keeps tokens and details',
	static function ( EPM_Test_Runner $t ) use ( $reset, $shows, $new_player ) {
		try {
			epm()->design->apply_preset( 'business-tuning' );
			epm()->design->update_details( [ 'player' => [ 'show_date' => false ] ] );
			$payload = Admin::design_export_payload();
			$t->same( 2, $payload['version'] ?? null, 'format 2' );
			$t->same( DesignSettings::EXPORT_VERSION, $payload['version'] ?? null, 'current format' );
			$t->same( false, $payload['details']['player']['show_volume'] ?? null, 'details exported' );
			$t->assert( ! isset( $payload['preset_extras'] ), 'no dead 1.3.0 maps exported' );
			$json = (string) wp_json_encode( $payload );

			$reset();
			$t->assert( $shows( $new_player(), 'volume' ), 'clean site shows the volume' );
			$result = Admin::design_from_payload( (array) json_decode( $json, true ) );
			$t->assert( is_array( $result ), 'valid' );
			update_option( DesignSettings::OPTION, $result );
			$t->same( '#b9ff22', epm()->design->get( 'accent' ), 'tokens imported' );
			$t->assert( ! $shows( $new_player(), 'volume' ), 'details imported and active' );
			$t->assert( ! $shows( $new_player(), 'date' ), 'customized detail imported' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'a format-1 file (1.1–1.3) imports its maps as suggestions, never active',
	static function ( EPM_Test_Runner $t ) use ( $reset, $shows, $new_player ) {
		try {
			epm()->design->update_details( [ 'player' => [ 'show_date' => true ] ] );
			$result = Admin::design_from_payload(
				[
					'format'        => 'epm-design',
					'version'       => 1,
					'preset'        => 'business-tuning',
					'design'        => [ 'accent' => '#b9ff22', 'default_player_layout' => 'editorial' ],
					'preset_extras' => [
						'visibility'  => [ 'show_description' => true, 'show_artwork' => 'nope' ],
						'player'      => [ 'show_volume' => false ],
						'episodeList' => [ 'show_excerpt' => false, 'show_<script>' => true ],
					],
				]
			);
			$t->assert( is_array( $result ), 'valid' );
			update_option( DesignSettings::OPTION, $result );
			$t->same( '#b9ff22', epm()->design->get( 'accent' ), 'tokens imported' );
			$t->assert( $shows( $new_player(), 'volume' ), 'the volume stays' );
			$t->assert( $shows( $new_player(), 'date' ), 'the site\'s own details stay' );
			$suggested = (array) epm()->design->get( 'details_suggested' );
			$t->same( false, $suggested['player']['show_volume'] ?? null, 'volume suggested' );
			$t->same( false, $suggested['list']['show_excerpt'] ?? null, 'list map suggested' );
			$t->same( false, $suggested['player']['show_artwork'] ?? null, '"nope" is false' );
			$t->assert( ! isset( $suggested['list']['show_script'] ) && ! isset( $suggested['list']['show_<script>'] ), 'unknown keys dropped' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'a format-2 file is validated like the rest',
	static function ( EPM_Test_Runner $t ) use ( $reset ) {
		try {
			$t->assert( is_wp_error( Admin::design_from_payload( [ 'format' => 'epm-design', 'version' => 3, 'design' => [] ] ) ), 'newer version rejected' );
			$result = Admin::design_from_payload(
				[
					'format'  => 'epm-design',
					'version' => 2,
					'design'  => [ 'accent' => '#000000' ],
					'details' => [
						'player'  => [ 'show_volume' => '0', 'show_title' => [ 'x' ], 'bogus' => true ],
						'unknown' => [ 'show_volume' => true ],
						'list'    => 'nonsense',
					],
				]
			);
			$t->assert( is_array( $result ), 'valid' );
			$t->same( [ 'player' => [ 'show_volume' => false ] ], array_filter( (array) $result['details'] ), 'only known contexts and details with scalar values' );
		} finally {
			$reset();
		}
	}
);

WP_CLI::log( 'The Design screen preview renders what the frontend renders (DESIGN-N3)' );

/**
 * Outer markup of the first player or list inside some markup (or inside
 * the Design screen preview block with the given name), serialized the
 * same way for both sides, without per-render IDs.
 */
$part = static function ( string $html, string $what, string $preview_name = '' ): string {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
	libxml_clear_errors();
	$xp    = new DOMXPath( $dom );
	$scope = '';
	if ( '' !== $preview_name ) {
		$scope = '//*[@data-epm-preview-part="' . $preview_name . '"]';
	}
	$query = 'player' === $what
		? $scope . '//*[@data-epm-player]'
		: $scope . '//*[contains(concat(" ", normalize-space(@class), " "), " epm-episode-list ")]';
	$nodes = $xp->query( $query );
	if ( ! $nodes || 0 === $nodes->length ) {
		return '';
	}
	$out = (string) $dom->saveHTML( $nodes->item( 0 ) );
	$out = (string) preg_replace( '/\s(id|for|aria-controls|aria-labelledby|aria-describedby)="[^"]*"/', '', $out );
	$out = (string) preg_replace( '/\sdata-epm-page-episode(="[^"]*")?/', '', $out );
	return trim( (string) preg_replace( '/>\s+</', '><', $out ) );
};

$t->test(
	'player, episode page and list previews equal the frontend for three presets',
	static function ( EPM_Test_Runner $t ) use ( $reset, $part ) {
		$latest = (int) epm()->episodes->get_latest( true )->ID;
		try {
			foreach ( [ 'neutral', 'business-tuning', 'minimal' ] as $preset ) {
				epm()->design->apply_preset( $preset );
				ob_start();
				( new Admin() )->render_design();
				$html = (string) ob_get_clean();

				$widget = $part( epm_test_widget( 'epm-podcast-player', [ 'epm_schema' => '2', 'source' => 'specific', 'episode_id' => $latest ] ), 'player' );
				$shown  = $part( $html, 'player', 'player' );
				$t->assert( '' !== $shown, $preset . ': player preview found' );
				$t->same( $widget, $shown, $preset . ': Player widget markup' );

				$page  = $part( epm_test_episode_page( $latest ), 'player' );
				$shown = $part( $html, 'player', 'episode-page' );
				$t->assert( '' !== $shown, $preset . ': episode page preview found' );
				$t->same( $page, $shown, $preset . ': automatic episode page player markup' );

				$list  = $part( epm_test_widget( 'epm-episode-list', [ 'epm_schema' => '2', 'number' => 2 ] ), 'list' );
				$shown = $part( $html, 'list', 'list' );
				$t->assert( '' !== $shown, $preset . ': list preview found' );
				$t->same( epm_test_signature( $list ), epm_test_signature( $shown ), $preset . ': list details' );
			}
		} finally {
			$reset();
		}
	}
);

WP_CLI::log( 'Layout choices are stored (DESIGN-N1) and the preset layout key (DESIGN-N4)' );

$t->test(
	'layout controls default to "Default (Podcast → Design: …)" so every explicit layout is stored',
	static function ( EPM_Test_Runner $t ) use ( $reset, $player, $new_player ) {
		try {
			foreach ( [ 'epm-podcast-player', 'epm-latest-episode', 'epm-episode-list' ] as $type ) {
				$controls = epm_test_controls( $type );
				$control  = $controls['layout'] ?? [];
				$t->same( '', $control['default'] ?? null, $type . ': default is ""' );
				$t->assert( false !== strpos( (string) ( $control['options'][''] ?? '' ), 'Podcast → Design' ), $type . ': the default option names Podcast → Design' );
				$schema = $controls['epm_schema'] ?? [];
				$t->assert( ! empty( $schema['save_default'] ) && '2' === ( $schema['default'] ?? '' ), $type . ': schema marker is always saved' );
			}

			$t->same( 'minimal', $player( $new_player( [ 'layout' => 'minimal' ] ) )['layout'], 'explicit Minimal' );
			epm()->design->apply_preset( 'business-tuning' );
			$t->same( 'minimal', $player( $new_player( [ 'layout' => 'minimal' ] ) )['layout'], 'explicit Minimal kept after a preset change' );
			$t->same( 'editorial', $player( $new_player( [ 'layout' => '' ] ) )['layout'], 'Default follows the preset' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'the preset "layout" key sets the player layout; the unused preset import/export is gone',
	static function ( EPM_Test_Runner $t ) use ( $reset ) {
		$filter = static function ( $presets ) {
			$presets['test-layout-only'] = [
				'name'   => 'Layout only',
				'layout' => 'full',
				'tokens' => [ 'accent' => '#222222' ],
			];
			return $presets;
		};
		add_filter( 'epm_presets', $filter );
		try {
			$t->assert( epm()->design->apply_preset( 'test-layout-only' ), 'applied' );
			$t->same( 'full', epm()->design->get( 'default_player_layout' ), 'layout key aliases the player layout token' );
			$t->assert( ! method_exists( epm()->presets, 'import' ) && ! method_exists( epm()->presets, 'export' ), 'no unused import/export' );
			foreach ( epm()->presets->all() as $id => $preset ) {
				if ( 'test-layout-only' !== $id ) {
					$t->assert( ! isset( $preset['layout'] ), $id . ': no duplicate layout key' );
				}
			}
		} finally {
			remove_filter( 'epm_presets', $filter );
			$reset();
		}
	}
);

$t->test(
	'the Design screen tells the truth: no "Installed by the preset" claim, an editable details form',
	static function ( EPM_Test_Runner $t ) use ( $reset ) {
		try {
			ob_start();
			( new Admin() )->render_design();
			$html = (string) ob_get_clean();
			$t->assert( false === strpos( $html, 'Installed by the preset' ), 'old claim removed' );
			$t->assert( false !== strpos( $html, 'id="epm-design-form"' ), 'details form' );
			foreach ( \EPM\Details::CONTEXTS as $context ) {
				$t->assert( false !== strpos( $html, 'name="epm_design_settings[details][' . $context . '][show_' ), 'fields for ' . $context );
			}
			$t->assert( false !== strpos( $html, 'data-epm-preview-part="episode-page"' ), 'episode page preview' );
		} finally {
			$reset();
		}
	}
);

$t->test(
	'no plugin-triggered _doing_it_wrong notices during the run',
	static function ( EPM_Test_Runner $t ) {
		$t->same( [], $GLOBALS['epm_test_doing_it_wrong'] );
	}
);

$reset();
$t->finish();
