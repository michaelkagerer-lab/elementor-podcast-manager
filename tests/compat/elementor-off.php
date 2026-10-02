<?php
/** QA-01: podcast management and publishing work with Elementor inactive. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'EPM_TEST_SITE' ) || ! is_file( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.epm-test-site' ) ) {
	throw new RuntimeException( 'Requires a marked disposable test site.' );
}
require_once __DIR__ . '/../integration/lib.php';
$t = new EPM_Test_Runner();
$t->test( 'podcasts remain usable with Elementor off', static function ( EPM_Test_Runner $t ) {
	$t->same( false, epm()->has_elementor(), 'Elementor is inactive' );
	$t->assert( post_type_exists( 'podcast_episode' ) && taxonomy_exists( 'podcast_topic' ), 'management post type and topics remain registered' );
	$fixtures = get_option( 'epm_test_fixtures' );
	$id = (int) $fixtures['ep1'];
	$html = do_shortcode( '[podcast_player id="' . $id . '"]' );
	$t->assert( false !== strpos( $html, 'data-epm-player' ), 'the player shortcode renders' );
	$t->assert( false !== strpos( do_shortcode( '[podcast_episodes limit="10"]' ), esc_html( get_the_title( $id ) ) ), 'episode lists render' );
	$t->assert( false !== strpos( epm_test_episode_page( $id ), 'data-epm-player' ), 'automatic episode pages render' );
	$feed = epm_test_feed()->saveXML();
	$t->assert( false !== strpos( $feed, '<rss' ) && false !== strpos( $feed, '<enclosure' ), 'the podcast RSS feed has playable episodes' );
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'toplevel_page_epm-dashboard' );
	ob_start();
	epm()->elementor_missing_notice();
	$notice = ob_get_clean();
	$t->assert( false !== strpos( $notice, 'Elementor is not active' ), 'the admin notice explains the missing widgets' );
	$t->assert( false !== strpos( $notice, 'RSS feed keep working' ), 'the notice explains that management and publishing still work' );
	set_current_screen( 'dashboard' );
	ob_start();
	epm()->elementor_missing_notice();
	$t->same( '', ob_get_clean(), 'the notice stays on podcast screens' );
} );
$t->finish();
