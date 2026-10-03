<?php
/** UX clarity: editor truth, shared save and usable starter content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/lib.php';
$t = new EPM_Test_Runner();
wp_set_current_user( 1 );
$render = static function ( $method ) {
 ob_start(); ( new \EPM\Admin() )->$method(); return ob_get_clean();
};
$t->test( 'UXC-01: editor fallback explains why a current-episode preview differs from a normal page', static function ( $t ) {
 $editor = \Elementor\Plugin::$instance->editor;
 $old = $GLOBALS['post'] ?? null;
 $GLOBALS['post'] = null;
 $editor->set_edit_mode( true );
 try {
  $widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance( [ 'id' => 'uxpreview', 'elType' => 'widget', 'widgetType' => 'epm-podcast-player', 'settings' => [ 'source' => 'current' ] ] );
  ob_start(); $widget->render_content(); $html = ob_get_clean();
  $t->assert( str_contains( $html, 'data-epm-preview-context' ), 'a sample episode is visibly identified as preview-only' );
 } finally { $editor->set_edit_mode( false ); $GLOBALS['post'] = $old; }
 $t->same( null, epm()->renderer->resolve_episode( 'current' ), 'normal pages still do not silently acquire an episode' );
} );
$t->test( 'UXC-02: all editable design values belong to one save form', static function ( $t ) use ( $render ) {
 $html = $render( 'render_design' );
 $dom = new DOMDocument(); @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html ); $xpath = new DOMXPath( $dom );
 $fields = $xpath->query( '//input[@type="checkbox" and contains(@name,"[details]")]' );
 $t->assert( $fields->length > 20, 'detail settings submit through the design option' );
 foreach ( $fields as $field ) {
  $owner = $field; while ( $owner && 'form' !== $owner->nodeName ) { $owner = $owner->parentNode; }
  $t->same( 'epm-design-form', $owner ? $owner->getAttribute( 'id' ) : '', 'every checkbox belongs to the shared save' );
 }
 $t->assert( ! str_contains( $html, '>Save details<' ), 'no competing details-save button' );
} );
$t->test( 'UXC-03: design defaults and overrides have an actionable explanation', static function ( $t ) use ( $render ) {
 $t->assert( str_contains( $render( 'render_design' ), 'data-epm-design-inheritance' ), 'the hierarchy is explained next to design controls' );
} );
$t->test( 'UXC-04: dashboard chooses a next action before presenting the status cards', static function ( $t ) use ( $render ) {
 $html = $render( 'render_dashboard' );
 $t->assert( str_contains( $html, 'data-epm-next-action' ), 'a next action is shown' );
 $t->assert( strpos( $html, 'data-epm-next-action' ) < strpos( $html, 'epm-dashboard__grid' ), 'action precedes supporting status' );
} );
$t->test( 'UXC-05: Elementor starter works immediately and keeps shortcode fallback', static function ( $t ) {
 $saved = get_option( \EPM\AdminPages::SETUP_OPTION );
 $page = null;
 try {
  \EPM\AdminPages::update_setup_state( [ 'page_id' => 0 ] );
  $page = \EPM\AdminPages::create_podcast_page();
  $t->assert( ! is_wp_error( $page ), 'starter created' );
  $data = json_decode( get_post_meta( $page['id'], '_elementor_data', true ), true );
  $t->assert( is_array( $data ) && count( $data ) > 0, 'starter contains editable Elementor components' );
  if ( is_array( $data ) ) {
   foreach ( $data[0]['elements'][0]['elements'] as $element ) {
    $t->assert( null !== \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $element['widgetType'] ), 'each starter widget exists' );
   }
  }
  $t->assert( str_contains( get_post( $page['id'] )->post_content, '[podcast_latest]' ), 'block fallback remains usable without Elementor' );
  $again = \EPM\AdminPages::create_podcast_page();
  $t->same( $page['id'], $again['id'], 'existing starter is reused without replacement' );
 } finally {
  if ( is_array( $page ) ) { wp_delete_post( $page['id'], true ); }
  false === $saved ? delete_option( \EPM\AdminPages::SETUP_OPTION ) : update_option( \EPM\AdminPages::SETUP_OPTION, $saved );
 }
} );
$t->test( 'UXC-06: inherited detail controls identify their source', static function ( $t ) {
 $controls = epm_test_controls( 'epm-podcast-player' );
 $t->assert( str_contains( $controls['show_share']['options'][''], 'Podcast' ), 'Default names its source, rather than only its value' );
 $t->assert( isset( $controls['show_share']['conditions'] ), 'unsupported explicit layouts do not offer ineffective share settings' );
} );
$t->test( 'UXC-07: a combined save keeps untouched defaults inherited and retains unavailable flags', static function ( $t ) {
 $saved = get_option( \EPM\DesignSettings::OPTION );
 try {
  delete_option( \EPM\DesignSettings::OPTION );
  $input = epm()->design->all();
  $input['details_form'] = 1;
  foreach ( \EPM\Details::CONTEXTS as $context ) { $input['details'][$context] = \EPM\Details::neutral( $context ); }
  $clean = epm()->design->sanitize( $input );
  $t->same( [], $clean['details'], 'saving unchanged values does not freeze inherited defaults' );
  update_option( \EPM\DesignSettings::OPTION, array_merge( $clean, [ 'details' => [ 'list' => [ 'show_topics' => false ] ] ] ) );
  unset( $input['details']['list']['show_topics'] );
  $clean = epm()->design->sanitize( $input );
  $t->same( false, $clean['details']['list']['show_topics'], 'a flag absent from the form retains its saved value' );
  $input['details_reset'] = 1;
  $clean = epm()->design->sanitize( $input );
  $t->same( [], $clean['details'], 'explicit built-in reset removes site overrides, including unavailable flags' );
 } finally { false === $saved ? delete_option( \EPM\DesignSettings::OPTION ) : update_option( \EPM\DesignSettings::OPTION, $saved ); }
} );
$t->finish();
