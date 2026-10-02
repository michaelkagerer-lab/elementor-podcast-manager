<?php
/**
 * Small global helpers.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'epm_xml_chars' ) ) {
	/**
	 * Keep only characters XML 1.0 allows (its Char production): no
	 * control characters but tab and line breaks, no surrogates and no
	 * U+FFFE/U+FFFF. Invalid UTF-8 becomes U+FFFD. One such character,
	 * pasted into a field, would otherwise make the whole feed unreadable.
	 *
	 * @param string $text Text (UTF-8).
	 * @return string
	 */
	function epm_xml_chars( $text ): string {
		$text = (string) $text;

		if ( ! preg_match( '//u', $text ) ) {
			// Invalid UTF-8: replace the broken sequences, keep the rest.
			$text = htmlspecialchars_decode( htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_NOQUOTES );
		}

		return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
	}
}

if ( ! function_exists( 'epm_esc_xml' ) ) {
	/**
	 * Escape a string for XML output (RSS feed).
	 *
	 * Characters XML does not allow are removed first (epm_xml_chars()).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	function epm_esc_xml( $text ): string {
		return htmlspecialchars( epm_xml_chars( $text ), ENT_XML1 | ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
