<?php
/**
 * Helper process for tests/integration/media.php (not a suite of its own:
 * run-all.sh skips files starting with "_"). Started with
 * WP_CLI::runcommand( 'eval-file …', [ 'launch' => true ] ), so it can die
 * the way a web request dies, without taking the test run with it.
 *
 *   die-after-move  Step the running import; when the audio of
 *                   "half-moved-2" has been moved into the uploads folder,
 *                   run out of memory (what WordPress core's image probe
 *                   did with long audio files, IMPB-N1).
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epm_child_mode = (string) ( $args[0] ?? '' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP-CLI passes $args.

if ( 'die-after-move' === $epm_child_mode ) {
	add_filter(
		'wp_handle_upload',
		static function ( $upload ) {
			if ( false !== strpos( (string) ( $upload['file'] ?? '' ), 'half-moved-2' ) ) {
				// Print the fatal error on stderr whatever php.ini says: with a
				// production php.ini (CI) PHP shows nothing, and WordPress only its
				// generic "critical error" message.
				ini_set( 'display_errors', 'stderr' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
				ini_set( 'memory_limit', (string) ( memory_get_usage() + 16 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
				$GLOBALS['epm_child_hog'] = str_repeat( 'x', 512 * MB_IN_BYTES );
			}
			return $upload;
		}
	);
	for ( $epm_child_i = 0; $epm_child_i < 5; $epm_child_i++ ) {
		$epm_child_state = EPM\ImportJob::step( 8.0 );
		if ( 'running' !== $epm_child_state['status'] ) {
			break;
		}
	}
	echo "The child finished without dying.\n";
}
