<?php
/**
 * Plugin Name: EPM test HTTP fixtures
 * Description: Test sites only (installed by tests/bin/setup-wp.sh). Answers outgoing HTTP requests for *.example.test and Apple's podcast lookup from tests/fixtures/feeds/, so imports and syncs run offline and give the same result every time.
 *
 * What it serves (everything else passes through untouched):
 *
 * - https://feeds.example.test/<name>.xml            tests/fixtures/feeds/<name>.xml (real feeds)
 * - https://feeds.example.test/synthetic/<name>      tests/fixtures/feeds/synthetic/<name>
 * - https://feeds.example.test/negative/<name>.html  bot-protection pages, with the HTTP status in
 *                                                    the file name (…-http403.html answers 403)
 * - https://feeds.example.test/media/<name>.mp3|m4a  a generated silent MP3 (5 s)
 * - https://feeds.example.test/media/<name>.png      a generated square PNG (1400 px, or
 *                                                    <name>-<w>x<h>.png)
 * - https://show.example.test/…                      web pages that link to a feed (see page())
 * - https://itunes.apple.com/lookup?id=…             Apple's lookup API for the IDs in APPLE_IDS
 * - https://api.podcastindex.org/…                   Podcast Index (the "feed updated" ping): a
 *                                                    fixed success answer, so test sites never
 *                                                    notify the real service
 *
 * URLs that pass through a download-measurement prefix
 * (https://op3.dev/e/feeds.example.test/…) are served like the URL after
 * the prefix. Feeds answer conditional requests (If-None-Match) with 304.
 *
 * The integration tests (tests/integration/hosting.php) load this file
 * directly and use EPM_Test_HTTP::$routes for dynamic answers,
 * EPM_Test_HTTP::$log to inspect requests and EPM_Test_HTTP::$offline to
 * refuse every other outgoing request.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Never on a live site: this answers HTTP requests with test data.
if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
	return;
}

if ( ! class_exists( 'EPM_Test_HTTP' ) ) {

	/**
	 * Fixture HTTP server behind the pre_http_request filter.
	 */
	final class EPM_Test_HTTP {

		/**
		 * Apple Podcasts IDs the lookup API knows.
		 */
		public const APPLE_IDS = [
			'1000000001' => 'https://feeds.example.test/synthetic/locked-show.xml',
		];

		/**
		 * In-process routes: exact URL => callable( array $args, string $url ): array|WP_Error.
		 *
		 * @var array<string, callable>
		 */
		public static array $routes = [];

		/**
		 * Requests answered in this process: [ url, method, headers ].
		 *
		 * @var array<int, array{url: string, method: string, headers: array<string, string>}>
		 */
		public static array $log = [];

		/**
		 * Refuse every other request to a non-local host.
		 *
		 * @var bool
		 */
		public static bool $offline = false;

		/**
		 * Fixture directory (tests/fixtures/feeds/).
		 *
		 * @return string
		 */
		public static function dir(): string {
			return dirname( __DIR__ ) . '/feeds/';
		}

		/**
		 * The pre_http_request filter.
		 *
		 * @param false|array|WP_Error $pre  Short-circuit value.
		 * @param array                $args Request arguments.
		 * @param string               $url  URL.
		 * @return false|array|WP_Error
		 */
		public static function filter( $pre, $args, $url ) {
			if ( false !== $pre ) {
				return $pre;
			}

			$url      = (string) $url;
			$args     = (array) $args;
			$response = self::answer( $url, $args );

			if ( null === $response ) {
				return $pre;
			}

			$headers = [];
			foreach ( (array) ( $args['headers'] ?? [] ) as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = (string) $value;
			}
			self::$log[] = [
				'url'     => $url,
				'method'  => (string) ( $args['method'] ?? 'GET' ),
				'headers' => $headers,
			];

			// download_url() streams the body into a temporary file.
			if ( is_array( $response ) && ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( (string) $args['filename'], (string) $response['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$response['filename'] = (string) $args['filename'];
				$response['body']     = '';
			}

			return $response;
		}

		/**
		 * Answer a request, or null to let it through.
		 *
		 * @param string $url  URL.
		 * @param array  $args Request arguments.
		 * @return array|WP_Error|null
		 */
		public static function answer( string $url, array $args ) {
			if ( isset( self::$routes[ $url ] ) ) {
				return call_user_func( self::$routes[ $url ], $args, $url );
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			// Podcast Index notifications from test sites never leave the
			// machine; tests read them from the log.
			if ( 'api.podcastindex.org' === $host ) {
				return self::response( 200, '{"status":"true","description":"Feed marked for immediate update."}', [ 'content-type' => 'application/json' ] );
			}

			if ( 'itunes.apple.com' === $host && 0 === strpos( (string) wp_parse_url( $url, PHP_URL_PATH ), '/lookup' ) ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$feed    = self::APPLE_IDS[ (string) ( $query['id'] ?? '' ) ] ?? '';
				$results = '' !== $feed ? [ [ 'kind' => 'podcast', 'feedUrl' => $feed ] ] : [];
				return self::response( 200, (string) wp_json_encode( [ 'resultCount' => count( $results ), 'results' => $results ] ), [ 'content-type' => 'application/json' ] );
			}

			// The first *.example.test host in the URL, also behind prefixes
			// such as https://op3.dev/e/feeds.example.test/media/1.mp3.
			if ( preg_match( '#(?:^https?://|/)([a-z0-9-]+(?:\.[a-z0-9-]+)*\.example\.test)(/[^?\#]*)?#i', $url, $m ) ) {
				return self::serve( strtolower( $m[1] ), '' !== ( $m[2] ?? '' ) ? $m[2] : '/', $args );
			}

			if ( self::$offline && ! in_array( $host, [ 'localhost', '127.0.0.1', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ], true ) ) {
				return new WP_Error( 'http_request_failed', 'Offline test run: ' . $url );
			}

			return null;
		}

		/**
		 * Serve a *.example.test path.
		 *
		 * @param string $host Host.
		 * @param string $path Path.
		 * @param array  $args Request arguments.
		 * @return array
		 */
		private static function serve( string $host, string $path, array $args ): array {
			if ( 'show.example.test' === $host ) {
				$page = self::page( $path );
				return null === $page ? self::response( 404, 'Not found' ) : self::response( 200, $page, [ 'content-type' => 'text/html; charset=UTF-8' ] );
			}

			if ( 'feeds.example.test' !== $host || false !== strpos( $path, '..' ) ) {
				return self::response( 404, 'Not found' );
			}

			// Like a real server, files come with their length.
			if ( preg_match( '#^/media/([a-z0-9_-]+)\.(mp3|m4a)$#i', $path, $m ) ) {
				$body = self::mp3( 5 );
				return self::response(
					200,
					$body,
					[
						'content-type'   => 'mp3' === strtolower( $m[2] ) ? 'audio/mpeg' : 'audio/mp4',
						'content-length' => (string) strlen( $body ),
					]
				);
			}

			if ( preg_match( '#^/media/([a-z0-9_-]+?)(?:-(\d+)x(\d+))?\.png$#i', $path, $m ) ) {
				$width  = isset( $m[2] ) ? (int) $m[2] : 1400;
				$height = isset( $m[3] ) ? (int) $m[3] : 1400;
				$body   = self::png( $width, $height );
				return self::response(
					200,
					$body,
					[
						'content-type'   => 'image/png',
						'content-length' => (string) strlen( $body ),
					]
				);
			}

			$file = self::dir() . ltrim( $path, '/' );
			if ( ! is_file( $file ) ) {
				return self::response( 404, 'Not found' );
			}

			$body   = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
			$status = preg_match( '/-http(\d{3})\.html$/', $file, $code ) ? (int) $code[1] : 200;
			$types  = [
				'xml'  => 'application/rss+xml; charset=UTF-8',
				'json' => 'application/json',
				'vtt'  => 'text/vtt',
				'srt'  => 'application/x-subrip',
				'html' => 'text/html; charset=UTF-8',
				'txt'  => 'text/plain; charset=UTF-8',
			];
			$etag   = '"' . md5( $body ) . '"';

			if ( 200 === $status ) {
				$sent = (string) ( $args['headers']['If-None-Match'] ?? $args['headers']['if-none-match'] ?? '' );
				if ( '' !== $sent && $sent === $etag ) {
					return self::response( 304, '', [ 'etag' => $etag ] );
				}
			}

			return self::response(
				$status,
				$body,
				[
					'content-type'   => $types[ strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ] ?? 'application/octet-stream',
					'content-length' => (string) strlen( $body ),
					'etag'           => $etag,
					'last-modified'  => 'Wed, 30 Sep 2026 06:00:00 GMT',
				]
			);
		}

		/**
		 * Web pages of a made-up show.
		 *
		 * @param string $path Path.
		 * @return string|null
		 */
		private static function page( string $path ): ?string {
			$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Synthetic Show</title>';
			$foot = '</head><body><h1>Synthetic Show</h1></body></html>';

			switch ( $path ) {
				case '/':
					// Comments feed first: the podcast feed must still win.
					return $head
						. '<link rel="alternate" type="application/rss+xml" title="Synthetic Show &raquo; Comments Feed" href="https://show.example.test/comments/feed/" />'
						. '<link rel="alternate" type="application/rss+xml" title="Synthetic Show Podcast" href="https://feeds.example.test/synthetic/locked-show.xml" />'
						. $foot;
				case '/relative/':
					return $head . '<link href="/podcast.xml" type="application/rss+xml" rel="alternate">' . $foot;
				case '/no-feed/':
					return $head . $foot;
				case '/podcast.xml':
					return (string) file_get_contents( self::dir() . 'synthetic/paged-2.xml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
			}

			return null;
		}

		/**
		 * A response array as WP_Http returns it.
		 *
		 * @param int                   $status  HTTP status.
		 * @param string                $body    Body.
		 * @param array<string, string> $headers Lowercase header names.
		 * @param string                $final   Final URL after redirects ('' for none).
		 * @param int[]                 $hops    Status codes of the redirects followed.
		 * @return array
		 */
		public static function response( int $status, string $body, array $headers = [], string $final = '', array $hops = [] ): array {
			$response = [
				'headers'  => $headers,
				'body'     => $body,
				'response' => [
					'code'    => $status,
					'message' => get_status_header_desc( $status ),
				],
				'cookies'  => [],
				'filename' => null,
			];

			// Redirects are visible to callers through the Requests object
			// (Hosting::fetch() reads its history for permanent moves).
			if ( '' !== $final && class_exists( '\WpOrg\Requests\Response' ) && class_exists( 'WP_HTTP_Requests_Response' ) ) {
				$requests              = new \WpOrg\Requests\Response();
				$requests->status_code = $status;
				$requests->body        = $body;
				$requests->url         = $final;
				$requests->success     = $status >= 200 && $status < 300;
				foreach ( $headers as $name => $value ) {
					$requests->headers[ $name ] = $value;
				}
				foreach ( $hops as $code ) {
					$hop              = new \WpOrg\Requests\Response();
					$hop->status_code = (int) $code;
					$requests->history[] = $hop;
				}
				$response['http_response'] = new WP_HTTP_Requests_Response( $requests );
			}

			return $response;
		}

		/**
		 * Silent MPEG-1 Layer III (32 kbps, 32 kHz, mono): 144-byte frames of 36 ms.
		 *
		 * @param int $seconds Duration.
		 * @return string
		 */
		public static function mp3( int $seconds ): string {
			return str_repeat( "\xFF\xFB\x18\xC0" . str_repeat( "\0", 140 ), (int) ceil( $seconds / 0.036 ) );
		}

		/**
		 * Single-colour PNG.
		 *
		 * @param int $width  Width.
		 * @param int $height Height.
		 * @return string
		 */
		public static function png( int $width, int $height ): string {
			$image = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 190, 60, 40 ) );
			ob_start();
			imagepng( $image );
			imagedestroy( $image );

			return (string) ob_get_clean();
		}
	}

	add_filter( 'pre_http_request', [ 'EPM_Test_HTTP', 'filter' ], 10, 3 );
}
