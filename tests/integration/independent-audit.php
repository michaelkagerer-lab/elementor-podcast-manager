<?php
/** Independent review: exercise Elementor's real registration lifecycle. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/lib.php';

$t = new EPM_Test_Runner();

$t->test(
	'IA-01: podcast widgets have a registered, visible Elementor category',
	static function ( EPM_Test_Runner $t ) {
		$categories = \Elementor\Plugin::$instance->elements_manager->get_categories();
		$t->assert( isset( $categories['epm-podcast'] ), 'the category must exist after Elementor initializes its own category list' );
		$widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		$podcast = array_filter( $widgets, static fn( $widget ) => str_starts_with( $widget->get_name(), 'epm-' ) );
		$t->same( 12, count( $podcast ), 'all twelve widgets register' );
		foreach ( $podcast as $widget ) {
			foreach ( $widget->get_categories() as $category ) {
				$t->assert( isset( $categories[ $category ] ), $widget->get_name() . ' must belong to an existing sidebar category' );
			}
		}
	}
);

$t->test(
	'IA-04: a queued directory ping respects privacy and opt-out changes before dispatch',
	static function ( EPM_Test_Runner $t ) {
		$public = get_option( 'blog_public' );
		$log = EPM_Test_HTTP::$log;
		$pings = static function (): int {
			return count( array_filter( EPM_Test_HTTP::$log, static fn( $entry ) => 'api.podcastindex.org' === wp_parse_url( $entry['url'], PHP_URL_HOST ) ) );
		};
		try {
			update_option( 'blog_public', '0' );
			EPM_Test_HTTP::$log = [];
			do_action( \EPM\Feed::PING_HOOK );
			$t->same( 0, $pings(), 'a site made private after publishing must not notify a directory' );
			update_option( 'blog_public', '1' );
			add_filter( \EPM\Feed::PING_FILTER, '__return_false' );
			EPM_Test_HTTP::$log = [];
			do_action( \EPM\Feed::PING_HOOK );
			$t->same( 0, $pings(), 'an opt-out added after publishing must prevent the queued notification' );
			remove_filter( \EPM\Feed::PING_FILTER, '__return_false' );
			EPM_Test_HTTP::$log = [];
			do_action( \EPM\Feed::PING_HOOK );
			$t->same( 1, $pings(), 'an eligible public site still sends its notification' );
		} finally {
			remove_filter( \EPM\Feed::PING_FILTER, '__return_false' );
			update_option( 'blog_public', $public );
			EPM_Test_HTTP::$log = $log;
		}
	}
);

$t->test(
	'IA-05: literal comparisons in feed titles survive alongside real HTML',
	static function ( EPM_Test_Runner $t ) {
		$parsed = ( new \EPM\FeedParser() )->parse( '<rss version="2.0"><channel><title>A &lt; B and C &gt; D</title><description><![CDATA[<b>Math</b>: 2 < 3 and 5 > 4]]></description><item><title>2 &lt; 3 and 5 &gt; 4</title><guid>math-episode</guid><enclosure url="https://media.example.test/math.mp3" type="audio/mpeg" length="100" /></item></channel></rss>' );
		$t->assert( ! is_wp_error( $parsed ), 'valid encoded comparisons parse' );
		if ( is_wp_error( $parsed ) ) { return; }
		$t->same( 'A < B and C > D', $parsed['channel']['title'], 'show title is kept' );
		$t->same( 'Math : 2 < 3 and 5 > 4', $parsed['channel']['description'], 'actual markup is removed without eating comparisons' );
		$t->same( '2 < 3 and 5 > 4', $parsed['items'][0]['title'], 'episode title is kept' );
	}
);

$t->test(
	'IA-08: safe GET and HEAD requests reject direct metadata addresses and redirects',
	static function ( EPM_Test_Runner $t ) {
		$origin = 'https://feeds.example.test/independent-metadata-redirect';
		foreach ( [ 'requests-requests.before_request', 'requests-requests.before_redirect' ] as $hook ) {
			foreach ( [ 'GET', 'HEAD' ] as $method ) {
				$reached = false;
				$seen_method = '';
				EPM_Test_HTTP::$routes[ $origin ] = static function ( $args ) use ( &$reached, &$seen_method, $hook ) {
					$seen_method = $args['method'];
					try {
						do_action( $hook, 'https://169.254.169.254/latest/meta-data/', [], null, [], (object) [ 'url' => 'https://feeds.example.test/' ] );
					} catch ( \WpOrg\Requests\Exception $error ) {
						return new WP_Error( $error->getType(), $error->getMessage() );
					}
					$reached = true;
					return [ 'headers' => [], 'body' => 'mock metadata', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
				};
				try {
					$result = 'HEAD' === $method ? \EPM\SafeHttp::head( $origin ) : \EPM\SafeHttp::get( $origin );
					$t->assert( is_wp_error( $result ), $method . ' ' . $hook . ' is refused' );
					$t->same( false, $reached, 'no request reaches the metadata target' );
					$t->same( $method, $seen_method, 'the safe wrapper preserves the HTTP method' );
				} finally {
					unset( EPM_Test_HTTP::$routes[ $origin ] );
				}
			}
		}
	}
);

$t->finish();
