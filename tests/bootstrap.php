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

$_core_env  = getenv( 'WP_CORE_DIR' );
$_core_dir  = rtrim( is_string( $_core_env ) && '' !== $_core_env ? $_core_env : '/tmp/wordpress', '/\\' );
$_elementor = $_core_dir . '/wp-content/plugins/elementor/elementor.php';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite in {$_tests_dir}.\n" .
		"Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n"
	);
	exit( 1 );
}

/*
 * Elementor is a hard prerequisite, not an optional extra that tests skip
 * around. The presentation layer is written against its dynamic-tag and widget
 * APIs, so a run without it would report OK while proving nothing about the
 * half of the plugin a visitor actually sees — which is the silent success this
 * suite exists to avoid.
 */
if ( ! file_exists( $_elementor ) ) {
	fwrite(
		STDERR,
		"Could not find Elementor at {$_elementor}.\n" .
		"Run bin/install-wp-tests.sh first, or set WP_CORE_DIR.\n"
	);
	exit( 1 );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $_elementor ): void {
		// Elementor first: it builds its managers on `plugins_loaded`, and the
		// plugin's own registration hooks hang off those.
		require $_elementor;
		require dirname( __DIR__ ) . '/veezi-wordpress-plugin.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

/**
 * Elementor talks to its own servers; the suite is not the place for it.
 *
 * Initialising the widget manager fetches a promotions feed from
 * `my.elementor.com`. That is a third party's request rather than anything
 * under test, and letting it out would make the suite slower, dependent on
 * somebody else's uptime, and quietly announce every run.
 *
 * Answered with an empty feed rather than a failure. Refusing the request works
 * out to the same thing for the site — no promotions — but reaches it through
 * Elementor's own error path, which in this version converts `false` to an
 * array and raises a PHP deprecation that has nothing to do with this plugin.
 *
 * Registered ahead of everything, so the request never reaches the plugin's own
 * test double, which counts what it is asked for.
 */
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( '' === $host || ! str_ends_with( $host, 'elementor.com' ) ) {
			return $preempt;
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '{"pro_widgets":[]}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	1,
	3
);

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
