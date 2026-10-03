<?php
/** Regressions for the approved ten UI measures. Disposable sites only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! is_file( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.epm-test-site' ) ) {
 throw new RuntimeException( 'Requires a marked disposable test site.' );
}
require_once __DIR__ . '/lib.php';
$t = new EPM_Test_Runner();
wp_set_current_user( 1 );
$render = static function ( $method ) { ob_start(); ( new ( in_array( $method, [ 'render_setup', 'render_hosting', 'render_distribution' ], true ) ? \EPM\AdminPages::class : \EPM\Admin::class )() )->$method(); return ob_get_clean(); };
$t->test( 'UA-07: summary distinguishes self-reported submission and listing', static function ( $t ) {
 $summary = \EPM\Admin::distribution_summary();
 $t->assert( isset( $summary['submitted'], $summary['listed'] ), 'separate counts exist' );
 if ( isset( $summary['submitted'], $summary['listed'] ) ) { $t->same( $summary['done'], $summary['submitted'] + $summary['listed'], 'counts are disjoint and preserve progress' ); }
} );
$t->test( 'UA-07: mixed records remain distinct and identify the next unsubmitted platform', static function ( $t ) {
 $before = get_option( \EPM\Directories::OPTION );
 try {
  update_option( \EPM\Directories::OPTION, [ 'apple' => [ 'status' => 'submitted' ], 'spotify' => [ 'status' => 'listed' ] ] );
  $summary = \EPM\Admin::distribution_summary();
  $t->same( 1, $summary['submitted'], 'only submitted count' );
  $t->same( 1, $summary['listed'], 'only listed count' );
  $t->assert( ! in_array( $summary['next'], [ 'apple', 'spotify', '' ], true ), 'next platform has neither status' );
 } finally { false === $before ? delete_option( \EPM\Directories::OPTION ) : update_option( \EPM\Directories::OPTION, $before ); }
} );
$t->test( 'UA-06: feed copy does not compete with next submission', static function ( $t ) use ( $render ) {
 $dom = new DOMDocument(); @$dom->loadHTML( $render( 'render_distribution' ) ); $x = new DOMXPath( $dom );
 $t->same( 0, $x->query( '//button[@data-epm-copy and contains(@class,"button-primary")]' )->length, 'copy is secondary' );
 $t->assert( str_contains( $dom->saveHTML(), 'data-dist-manual' ), 'self-reporting is explicit' );
} );
$t->test( 'UA-01/14: component selection and real unsaved render', static function ( $t ) use ( $render ) {
 $t->assert( str_contains( $render( 'render_design' ), 'data-epm-preview-component' ), 'component selector exists' );
 $t->assert( method_exists( \EPM\Admin::class, 'render_design_preview' ), 'read-only real renderer exists' );
} );
$t->test( 'UA-04: setup starts with the user situation', static function ( $t ) use ( $render ) {
 $t->assert( str_contains( $render( 'render_setup' ), 'data-epm-situation' ), 'new versus existing precedes hosting' );
} );
$t->test( 'UA-05: hosting exposes task navigation', static function ( $t ) use ( $render ) {
 $t->assert( str_contains( $render( 'render_hosting' ), 'data-epm-hosting-task' ), 'hosting, import and move navigation' );
} );
$t->test( 'UA-02: token provenance and undo reset are exposed', static function ( $t ) {
 $controls = epm_test_controls( 'epm-podcast-player' );
 $colors = array_filter( $controls, static fn( $c ) => 'color' === ( $c['type'] ?? '' ) && isset( $c['selectors'] ) && ( $c['condition']['style_source'] ?? '' ) === 'custom' );
 $t->assert( count( $colors ) > 0, 'color controls exist' );
 foreach ( $colors as $id => $color ) { $t->assert( ! empty( $color['description'] ), 'global value identified for ' . $id ); $t->assert( isset( $controls[ $id . '_inherit' ] ), 'explicit reset exists for ' . $id ); }
} );
$t->test( 'UA-10: starter choices have distinct content and episode context', static function ( $t ) {
 $t->assert( method_exists( \EPM\AdminPages::class, 'starter_content' ), 'shared native starter definitions exist' );
} );
$t->test( 'UA-01: unsaved preview renders details and keeps database settings', static function ( $t ) {
 $before = get_option( 'epm_design_settings' );
 $input = epm()->design->all(); $input['default_player_layout'] = 'full'; $input['details'] = [ 'player' => [ 'show_share' => false, 'show_download' => true ] ];
 $result = \EPM\Admin::render_design_preview( $input );
 $t->assert( ! str_contains( $result['html']['player'], 'data-epm-share-toggle' ), 'share removed before save' );
 $t->assert( str_contains( $result['html']['player'], 'epm-player__download' ), 'download appears before save' );
 $input['details']['player']['show_share'] = true;
 $t->assert( str_contains( \EPM\Admin::render_design_preview( $input )['html']['player'], 'data-epm-share-toggle' ), 'share returns immediately' );
 $t->same( $before, get_option( 'epm_design_settings' ), 'preview writes no design option' );
 $input['default_player_layout'] = 'minimal';
 $t->assert( str_contains( \EPM\Admin::render_design_preview( $input )['effects']['player']['show_share'], 'Hidden by' ), 'unavailable flags retain an honest layout explanation' );
} );
$t->test( 'UA-10: starters contain registered widgets and valid fallback shortcodes', static function ( $t ) {
 foreach ( [ 'show', 'archive', 'episode' ] as $kind ) {
  $starter = \EPM\AdminPages::starter_content( $kind );
  foreach ( $starter['elements'][0]['elements'][0]['elements'] as $widget ) {
   $t->assert( null !== \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget['widgetType'] ), 'starter widget exists' );
   if ( 'episode' === $kind ) { $t->same( 'current', $widget['settings']['source'], 'episode layout uses its actual context' ); }
  }
  preg_match_all( '/\[([a-z_]+)/', $starter['content'], $matches );
  foreach ( $matches[1] as $shortcode ) { $t->assert( shortcode_exists( $shortcode ), 'fallback shortcode exists: ' . $shortcode ); }
 }
 $t->same( [], \EPM\AdminPages::starter_content( 'invalid' ), 'unknown starter is rejected' );
} );
$t->test( 'UA-01: malformed preview values recover without a PHP error', static function ( $t ) {
 $result = \EPM\Admin::render_design_preview( [ 'accent' => [ 'bad' ], 'spacing' => [ 'bad' ], 'preset' => [ 'bad' ], 'details' => [ 'player' => [ 'show_share' => [ 'bad' ] ] ] ] );
 $t->assert( ! empty( $result['html']['player'] ), 'invalid values fall back to safe defaults' );
} );
$t->finish();
