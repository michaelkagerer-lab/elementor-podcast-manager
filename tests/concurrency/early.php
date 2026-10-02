<?php
/**
 * Loaded by run.sh (`wp --require`) before WordPress, for every phase of a
 * race scenario.
 *
 * A scenario that races the plugin's upgrade (upgrade-once) raises the
 * flag "hold-upgrade" in its run directory during setup: then WordPress
 * boots every role with the upgrade held back (epm_version reads as the
 * running version), and the role runs the upgrade itself at a barrier, so
 * both roles really start it at the same moment. Hooks are registered the
 * way WordPress accepts them before it loads ($wp_filter as an array).
 *
 * @package EPM
 */

$epm_race_early_dir = (string) getenv( 'EPM_RACE_DIR' );
if ( '' !== $epm_race_early_dir && file_exists( rtrim( $epm_race_early_dir, '/' ) . '/hold-upgrade' ) ) {
	$GLOBALS['epm_race_hold_upgrade']                      = true;
	$GLOBALS['wp_filter']['pre_option_epm_version'][10][] = [
		'function'      => static function ( $pre ) {
			return ! empty( $GLOBALS['epm_race_hold_upgrade'] ) && defined( 'EPM_VERSION' ) ? EPM_VERSION : $pre;
		},
		'accepted_args' => 1,
	];
}
