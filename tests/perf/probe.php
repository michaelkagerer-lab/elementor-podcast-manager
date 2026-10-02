<?php
/**
 * Plugin Name: EPM perf probe (test sites only)
 * Description: Linked as a must-use plugin by tests/perf/fpm.sh. A request
 * with the header "X-EPM-Perf: <label>" appends its wall time, peak memory,
 * query count and fatal error (if any) as one JSON line to the file named by
 * the environment variable EPM_PERF_LOG.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) || empty( $_SERVER['HTTP_X_EPM_PERF'] ) || '' === (string) getenv( 'EPM_PERF_LOG' ) ) {
	return;
}
if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
	return;
}

register_shutdown_function(
	static function () {
		global $wpdb;

		$error = error_get_last();
		$row   = [
			'label'   => substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_EPM_PERF'] ) ), 0, 80 ),
			'ms'      => round( ( microtime( true ) - (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) ) * 1000, 1 ),
			'peak_mb' => round( memory_get_peak_usage() / 1048576, 1 ),
			'limit'   => ini_get( 'memory_limit' ),
			'queries' => isset( $wpdb ) ? (int) $wpdb->num_queries : 0,
			'fatal'   => ( $error && in_array( $error['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) ? substr( (string) $error['message'], 0, 160 ) : '',
		];
		file_put_contents( (string) getenv( 'EPM_PERF_LOG' ), wp_json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
);
