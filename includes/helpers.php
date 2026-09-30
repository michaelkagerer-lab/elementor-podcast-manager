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
	 * @param string $text Raw text.
	 * @return string
	 */
	function epm_esc_xml( $text ): string {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}
}
