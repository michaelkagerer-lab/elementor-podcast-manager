<?php
/** Editorial queue permission, ordering and preservation regression checks. @package EPM */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/lib.php';
if ( ! is_file( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.epm-test-site' ) ) { WP_CLI::error( 'Disposable site required.' ); }
$t = new EPM_Test_Runner();
$admin = get_user_by( 'login', 'admin' )->ID;
wp_set_current_user( $admin );
$before = [];
$fx = get_option( 'epm_test_fixtures' );
foreach ( [ $fx['ep1'], $fx['ep2'] ] as $id ) { $before[ $id ] = [ get_post( $id ), get_post_meta( $id ), get_permalink( $id ) ]; }
$options = [ get_option( EPM\PodcastSettings::OPTION ), get_option( 'epm_design_settings' ) ];
$t->test( 'Queue is bounded and excludes published/private episodes', function ( $t ) {
	$drafts = EPM\Admin::dashboard_queue( 'draft' );
	$t->assert( count( $drafts ) <= 5 && count( $drafts ) > 0 );
	foreach ( $drafts as $post ) { $t->assert( 'draft' === $post->post_status ); }
	$t->assert( [] === EPM\Admin::dashboard_queue( 'private' ) );
	$t->assert( [] === EPM\Admin::dashboard_queue( 'publish' ) );
	$scheduled = EPM\Admin::dashboard_queue( 'future' );
	$t->assert( count( $scheduled ) <= 3 );
	$dates = array_column( $scheduled, 'post_date' ); $ordered = $dates; sort( $ordered );
	$t->assert( $dates === $ordered );
});
$user = wp_insert_user( [ 'user_login' => 'epm_queue_' . wp_generate_password( 8, false ), 'role' => 'contributor', 'user_pass' => wp_generate_password() ] );
$own = wp_insert_post( [ 'post_type' => EPM\EpisodePostType::CPT, 'post_status' => 'draft', 'post_author' => $user, 'post_title' => 'My private work in progress' ] );
$t->test( 'Contributor sees only own editable drafts; anonymous sees none', function ( $t ) use ( $user, $own ) {
	wp_set_current_user( $user );
	$rows = EPM\Admin::dashboard_queue( 'draft' );
	$t->assert( [ $own ] === array_column( $rows, 'ID' ) );
	wp_set_current_user( 0 );
	$t->assert( [] === EPM\Admin::dashboard_queue( 'draft' ) );
});
wp_set_current_user( $admin );
$t->test( 'Reading queue preserves published posts, metadata, URLs and settings', function ( $t ) use ( $before, $options ) {
	EPM\Admin::dashboard_queue( 'draft' ); EPM\Admin::dashboard_queue( 'future' );
	foreach ( $before as $id => $value ) { $t->assert( serialize( $value ) === serialize( [ get_post( $id ), get_post_meta( $id ), get_permalink( $id ) ] ) ); }
	$t->assert( $options === [ get_option( EPM\PodcastSettings::OPTION ), get_option( 'epm_design_settings' ) ] );
});
$t->test( 'Upgrade from rc.1 preserves published identities, feeds and chosen widget styles', function ( $t ) use ( $fx ) {
	$ids = [ $fx['ep1'], $fx['ep2'], $fx['elementor_page'] ];
	$snapshot = static function () use ( $ids ) {
		$state = [ 'settings' => get_option( EPM\PodcastSettings::OPTION ), 'design' => get_option( 'epm_design_settings' ), 'hosting' => get_option( EPM\Hosting::OPTION ), 'feed' => EPM\Hosting::public_feed_url() ];
		foreach ( $ids as $id ) { $state[ $id ] = [ get_post( $id )->to_array(), get_post_meta( $id ), get_permalink( $id ) ]; }
		$feed = epm_test_feed(); $items = [];
		foreach ( $feed->getElementsByTagName( 'item' ) as $item ) {
			$items[] = [ $item->getElementsByTagName( 'guid' )->item(0)->textContent, $item->getElementsByTagName( 'enclosure' )->item(0)->getAttribute( 'url' ) ];
		}
		$state['items'] = $items;
		return serialize( $state );
	};
	$before = $snapshot();
	update_option( 'epm_version', '1.5.0-rc.1' );
	epm()->maybe_upgrade();
	for ( $i = 0; $i < 10 && EPM\Upgrade::pending(); ++$i ) { EPM\Upgrade::run( 2 ); }
	$t->assert( '1.5.0-rc.3' === get_option( 'epm_version' ) );
	$t->assert( $before === $snapshot(), 'posts, metadata, permalinks, settings, widget data and RSS item identities remain identical' );
});
wp_delete_post( $own, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user );
$t->finish();
