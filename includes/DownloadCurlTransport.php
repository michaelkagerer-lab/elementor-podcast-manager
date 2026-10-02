<?php
/** Preserve response validators even when a bounded transfer is interrupted. */
namespace EPM;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadCurlTransport implements \WpOrg\Requests\Transport {
	private \WpOrg\Requests\Transport\Curl $curl;

	public function __construct( private MediaWatch $watch ) {
		$this->curl = new \WpOrg\Requests\Transport\Curl();
	}

	/** Delegate to WordPress' transport, observing its existing header parser. */
	public function request( $url, $headers = [], $data = [], $options = [] ) {
		$attach = function ( $handle, $args ) use ( $options ): void {
			if ( ( $args['filename'] ?? null ) === ( $options['filename'] ?? null ) ) {
				curl_setopt( $handle, CURLOPT_HEADERFUNCTION, [ $this, 'stream_headers' ] );
			}
		};
		add_action( 'http_api_curl', $attach, PHP_INT_MAX, 2 );
		try {
			return $this->curl->request( $url, $headers, $data, $options );
		} finally {
			remove_action( 'http_api_curl', $attach, PHP_INT_MAX );
		}
	}

	public function request_multiple( $requests, $options ) {
		return $this->curl->request_multiple( $requests, $options );
	}

	public static function test( $capabilities = [] ) {
		return \WpOrg\Requests\Transport\Curl::test( $capabilities );
	}

	/** Keep Requests' header parsing and observe the same header lines. */
	public function stream_headers( $handle, $headers ) {
		$this->watch->header( (string) $headers );
		return $this->curl->stream_headers( $handle, $headers );
	}
}
