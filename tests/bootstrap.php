<?php
/**
 * PHPUnit bootstrap: loads the plugin into a real WordPress install.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

// This file runs before WordPress exists, on the command line: plain PHP file
// and output functions are the only ones available, and there is no browser to
// escape output for.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

$_tests_env = getenv( 'WP_TESTS_DIR' );
$_tests_dir = rtrim( is_string( $_tests_env ) && '' !== $_tests_env ? $_tests_env : '/tmp/wordpress-tests-lib', '/\\' );

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite in {$_tests_dir}.\n" .
		"Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n"
	);
	exit( 1 );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/veezi-wordpress-plugin.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

/**
 * Every outbound request the plugin makes must be intercepted by a test.
 *
 * `pre_http_request` is the plugin's only I/O seam, so anything reaching the
 * network is a test that forgot to arrange a response — which would be slow,
 * flaky, and in this project would talk to a real cinema's ticketing account.
 * Running last, this sees whatever the test's own filter returned and only
 * objects when nothing did.
 */
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		throw new RuntimeException(
			"Unintercepted outbound HTTP request to {$url}. " .
			'Arrange a response through the test case\'s HTTP seam.'
		);
	},
	PHP_INT_MAX,
	3
);
