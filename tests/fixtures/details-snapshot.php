<?php
/**
 * Writes tests/fixtures/details-1.3.0.json: which details every consumer
 * in epm_test_details_consumers() shows on an untouched, freshly seeded
 * site (no Podcast → Design settings).
 *
 *   EPM_ALLOW_TEST_SEED=1 wp eval-file tests/fixtures/seed.php
 *   wp eval-file tests/fixtures/details-snapshot.php
 *
 * The committed file was generated with 1.3.0 plus the player fixes
 * (b728d96), before the details defaults existed. tests/integration/design.php
 * checks that an untouched site still renders exactly this.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/integration/lib.php';

if ( false !== get_option( 'epm_design_settings', false ) ) {
	WP_CLI::error( 'Seed the fixtures first: the snapshot needs a site without design settings.' );
}

$fx  = get_option( 'epm_test_fixtures' );
$out = [];
foreach ( epm_test_details_consumers( $fx ) as $name => $render ) {
	$out[ $name ] = epm_test_signature( $render() );
}

$json = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( __DIR__ . '/details-1.3.0.json', $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
WP_CLI::success( count( $out ) . ' consumers written.' );
