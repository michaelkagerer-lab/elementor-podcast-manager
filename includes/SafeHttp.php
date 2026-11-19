<?php
/**
 * Bounded WordPress HTTP requests that never downgrade HTTPS redirects.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SafeHttp {

	/** Keep WordPress URL validation and redirect history, guarding each hop. */
	public static function get( string $url, array $args = [] ) {
		$blocked = null;
		$secure = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$message = __( 'The host redirects from HTTPS to unencrypted HTTP. Ask the host to fix the redirect.', 'elementor-podcast-manager' );
		$guard = static function ( $location, $headers, $data, $options, $previous ) use ( $secure, $message, &$blocked ): void {
			if ( ! self::public_url( (string) $location ) ) {
				$blocked = new \WP_Error( 'epm_unsafe_url', __( 'The address (or an address it redirects to) is not allowed: it is not a public web address.', 'elementor-podcast-manager' ) );
				throw new \WpOrg\Requests\Exception( $blocked->get_error_message(), $blocked->get_error_code() );
			}
			$previous_secure = $secure || 'https' === strtolower( (string) wp_parse_url( (string) ( $previous->url ?? '' ), PHP_URL_SCHEME ) );
			if ( $previous_secure && 'https' !== strtolower( (string) wp_parse_url( (string) $location, PHP_URL_SCHEME ) ) ) {
				$blocked = new \WP_Error( 'epm_insecure_redirect', $message );
				throw new \WpOrg\Requests\Exception( $message, 'epm_insecure_redirect' );
			}
		};
		$initial = static function ( $request_url ) use ( &$blocked ): void {
			if ( ! self::public_url( (string) $request_url ) ) {
				$blocked = new \WP_Error( 'epm_unsafe_url', __( 'The address (or an address it redirects to) is not allowed: it is not a public web address.', 'elementor-podcast-manager' ) );
				throw new \WpOrg\Requests\Exception( $blocked->get_error_message(), $blocked->get_error_code() );
			}
		};
		// A DNS check and a later resolver lookup are different operations.
		// Pin the actual cURL connection, including each redirected request.
		$pin = static function ( $handle ) use ( &$blocked ): void {
			$current = (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL );
			$host = (string) wp_parse_url( $current, PHP_URL_HOST );
			$ip = gethostbyname( $host );
			if ( ! self::public_ip( $ip, $host, $current ) ) {
				$blocked = new \WP_Error( 'epm_unsafe_url', __( 'The address (or an address it redirects to) is not allowed: it is not a public web address.', 'elementor-podcast-manager' ) );
				throw new \WpOrg\Requests\Exception( $blocked->get_error_message(), $blocked->get_error_code() );
			}
			$port = (int) wp_parse_url( $current, PHP_URL_PORT ) ?: ( 'https' === wp_parse_url( $current, PHP_URL_SCHEME ) ? 443 : 80 );
			$ok = curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $ip ] )
				&& curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 )
				&& curl_setopt( $handle, CURLOPT_PROXY, '' )
				&& curl_setopt( $handle, CURLOPT_FRESH_CONNECT, true )
				&& curl_setopt( $handle, CURLOPT_FORBID_REUSE, true );
			if ( ! $ok ) {
				$blocked = new \WP_Error( 'epm_http_pin_failed', __( 'The server could not secure this download connection. Ask your web host to check PHP cURL.', 'elementor-podcast-manager' ) );
				throw new \WpOrg\Requests\Exception( $blocked->get_error_message(), $blocked->get_error_code() );
			}
		};
		$transport = static function ( &$request_url, &$headers, &$data, &$type, &$options ) use ( &$blocked ): void {
			$chosen = $options['transport'] ?? null;
			if ( ! \WpOrg\Requests\Transport\Curl::test() || ! empty( $options['proxy'] ) || ( null !== $chosen && ! ( $chosen instanceof \WpOrg\Requests\Transport\Curl ) && ! ( $chosen instanceof DownloadCurlTransport ) ) ) {
				$blocked = new \WP_Error( 'epm_http_transport', __( 'Secure feed downloads require a direct PHP cURL connection. Ask your web host to enable it. Existing episodes are unchanged.', 'elementor-podcast-manager' ) );
				throw new \WpOrg\Requests\Exception( $blocked->get_error_message(), $blocked->get_error_code() );
			}
			if ( null === $chosen ) { $options['transport'] = new \WpOrg\Requests\Transport\Curl(); }
		};
		add_action( 'requests-curl.before_send', $pin, PHP_INT_MAX, 1 );
		add_action( 'requests-requests.before_request', $transport, PHP_INT_MAX, 5 );
		add_action( 'requests-requests.before_redirect', $guard, 1, 5 );
		add_action( 'requests-requests.before_request', $initial, 0, 1 );
		try {
			$response = wp_safe_remote_request( $url, $args );
		} catch ( \WpOrg\Requests\Exception $error ) {
			if ( ! $blocked ) {
				throw $error;
			}
			$response = $blocked;
		} finally {
			remove_action( 'requests-curl.before_send', $pin, PHP_INT_MAX );
			remove_action( 'requests-requests.before_request', $transport, PHP_INT_MAX );
			remove_action( 'requests-requests.before_redirect', $guard, 1 );
			remove_action( 'requests-requests.before_request', $initial, 0 );
		}
		if ( $blocked ) {
			return $blocked;
		}
		// Test transports and custom HTTP clients may return a final response
		// without dispatching Requests hooks. Never accept a downgraded body.
		$raw = is_array( $response ) ? ( $response['http_response'] ?? null ) : null;
		if ( $secure && $raw instanceof \WP_HTTP_Requests_Response ) {
			$final_url = (string) $raw->get_response_object()->url;
			if ( '' !== $final_url && 'https' !== strtolower( (string) wp_parse_url( $final_url, PHP_URL_SCHEME ) ) ) {
				return new \WP_Error( 'epm_insecure_redirect', $message );
			}
		}
		return $response;
	}

	/** Apply current public-address restrictions on older supported core versions. */
	private static function public_url( string $url ): bool {
		// wp_safe_remote_request already applies core's protocol/port/host
		// validation. Only supplement ranges missing from older core releases;
		// mocked HTTP responses need not resolve a real hostname.
		$host = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' );
		$ip = gethostbyname( $host );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return true; // Core owns malformed hosts, IPv6 and unresolved-address validation.
		}
		return self::public_ip( $ip, $host, $url );
	}

	/** Validate the exact address used by the socket, never another DNS lookup. */
	private static function public_ip( string $ip, string $host, string $url ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { return false; }
		$p = array_map( 'intval', explode( '.', $ip ) );
		$restricted = ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
			|| ( 169 === $p[0] && 254 === $p[1] )
			|| ( 100 === $p[0] && 64 <= $p[1] && 127 >= $p[1] )
			|| ( 192 === $p[0] && 0 === $p[1] && in_array( $p[2], [ 0, 2 ], true ) )
			|| ( 192 === $p[0] && 88 === $p[1] && 99 === $p[2] )
			|| ( 198 === $p[0] && 51 === $p[1] && 100 === $p[2] )
			|| ( 203 === $p[0] && 0 === $p[1] && 113 === $p[2] )
			|| ( 198 === $p[0] && 18 <= $p[1] && 19 >= $p[1] )
			|| 224 <= $p[0];
		return ! $restricted || (bool) apply_filters( 'http_request_host_is_external', false, $host, $url );
	}

	/** HEAD requests use the same address and downgrade checks as downloads. */
	public static function head( string $url, array $args = [] ) {
		$args['method'] = 'HEAD';
		return self::get( $url, $args );
	}

	/** Body and uncompressed Content-Length both expose a truncated response. */
	public static function exceeds_limit( array $response, int $bytes ): bool {
		if ( strlen( (string) wp_remote_retrieve_body( $response ) ) > $bytes ) {
			return true;
		}
		$length = wp_remote_retrieve_header( $response, 'content-length' );
		$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );
		if ( is_array( $encoding ) ) {
			$encoding = implode( ',', $encoding );
		}
		return ( '' === $encoding || 'identity' === strtolower( (string) $encoding ) ) && is_numeric( $length ) && (int) $length > $bytes;
	}
}
