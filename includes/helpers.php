<?php
/**
 * Small global helpers.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'epm_esc_xml' ) ) {
	/**
	 * Escape a string for XML output (RSS feed).
	 *
	 * Control characters are removed first: XML 1.0 does not allow them, and
	 * one of them (pasted into a field) would make the whole feed invalid.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	function epm_esc_xml( $text ): string {
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $text );

		return htmlspecialchars( $text, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}
}
