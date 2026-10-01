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
		$blocked = false;
		$secure = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$message = __( 'The host redirects from HTTPS to unencrypted HTTP. Ask the host to fix the redirect.', 'elementor-podcast-manager' );
		$guard = static function ( $location, $headers, $data, $options, $previous ) use ( $secure, $message, &$blocked ): void {
			$previous_secure = $secure || 'https' === strtolower( (string) wp_parse_url( (string) ( $previous->url ?? '' ), PHP_URL_SCHEME ) );
			if ( $previous_secure && 'https' !== strtolower( (string) wp_parse_url( (string) $location, PHP_URL_SCHEME ) ) ) {
				$blocked = true;
				throw new \WpOrg\Requests\Exception( $message, 'epm_insecure_redirect' );
			}
		};
		add_action( 'requests-requests.before_redirect', $guard, 1, 5 );
		try {
			$response = wp_safe_remote_get( $url, $args );
		} catch ( \WpOrg\Requests\Exception $error ) {
			if ( ! $blocked ) {
				throw $error;
			}
			$response = new \WP_Error( 'epm_insecure_redirect', $message );
		} finally {
			remove_action( 'requests-requests.before_redirect', $guard, 1 );
		}
		if ( $blocked ) {
			return new \WP_Error( 'epm_insecure_redirect', $message );
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
