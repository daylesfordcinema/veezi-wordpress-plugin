<?php
/**
 * The test double for Veezi, installed at the plugin's only I/O seam.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests\Support;

use WP_Error;

/**
 * Answers outbound HTTP on `pre_http_request`.
 *
 * Responses are arranged per path rather than queued in order, so a test reads
 * as a description of the upstream's state rather than of the plugin's call
 * sequence — and stays passing when the plugin reorders its requests.
 */
final class FakeVeezi {

	/**
	 * Every request the plugin attempted, in order.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	public array $requests = array();

	/**
	 * Arranged answers, keyed by the path they match.
	 *
	 * @var array<string,array|WP_Error>
	 */
	private array $answers = array();

	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function unregister(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
	}

	/**
	 * Answer requests whose path contains $path with a JSON body.
	 *
	 * @param string $path   Matched against the request URL.
	 * @param mixed  $body   Encoded as JSON. Pass a string to send it verbatim,
	 *                       which is how malformed responses are arranged.
	 * @param int    $status HTTP status to answer with.
	 */
	public function will_return( string $path, mixed $body, int $status = 200 ): void {
		$this->answers[ $path ] = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Answer requests whose path contains $path with a transport failure —
	 * DNS, connection refused, timeout. This is what an outage looks like, and
	 * it is a different thing from a server that answers with an error status.
	 *
	 * @param string $path    Matched against the request URL.
	 * @param string $message What the HTTP client would have reported.
	 */
	public function will_fail( string $path, string $message = 'Could not resolve host.' ): void {
		$this->answers[ $path ] = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * @param  false|array|WP_Error $preempt Whatever an earlier filter returned.
	 * @param  array<string,mixed>  $args    Request arguments.
	 * @param  string               $url     The URL being requested.
	 * @return false|array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => (string) $url,
			'args' => (array) $args,
		);

		// Matched on the whole path, not a substring of the URL: `/v1/film`
		// otherwise also answers `/v1/filmpackage`, which is a real endpoint
		// returning something entirely different.
		$requested = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		foreach ( $this->answers as $path => $answer ) {
			if ( '/' . ltrim( $path, '/' ) === $requested ) {
				return $answer;
			}
		}

		return $preempt;
	}

	/**
	 * The last request whose URL contains $path.
	 *
	 * @param  string $path Matched against the request URL.
	 * @return array<string,mixed>|null
	 */
	public function last_request_to( string $path ): ?array {
		foreach ( array_reverse( $this->requests ) as $request ) {
			if ( str_contains( $request['url'], $path ) ) {
				return $request;
			}
		}

		return null;
	}
}
