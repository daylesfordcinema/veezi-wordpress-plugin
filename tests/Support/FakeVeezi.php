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
		$this->answers[ $path ] = $this->response(
			is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'application/json',
			$status
		);
	}

	/**
	 * Answer requests whose path contains $path with image bytes.
	 *
	 * Separate from will_return() only because the body is sent verbatim and
	 * the content type is the caller's to choose: what the plugin does with a
	 * poster depends entirely on what the bytes turn out to be, since Veezi's
	 * media URLs carry no file extension to go on.
	 *
	 * @param string $path         Matched against the request URL.
	 * @param string $bytes        The image itself.
	 * @param string $content_type What the CDN would call it.
	 */
	public function will_return_image( string $path, string $bytes, string $content_type = 'image/jpeg' ): void {
		$this->answers[ $path ] = $this->response( $bytes, $content_type );
	}

	/**
	 * The shape WordPress's HTTP layer hands back.
	 *
	 * @param  string $body         Sent verbatim.
	 * @param  string $content_type What the server calls it.
	 * @param  int    $status       HTTP status to answer with.
	 * @return array<string,mixed>
	 */
	private function response( string $body, string $content_type, int $status = 200 ): array {
		return array(
			'headers'  => array( 'content-type' => $content_type ),
			'body'     => $body,
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
				return is_array( $answer ) ? $this->deliver( $answer, (array) $args ) : $answer;
			}
		}

		return $preempt;
	}

	/**
	 * Hand back a response the way the transport would have.
	 *
	 * A streaming request — which is how WordPress downloads media — is asked to
	 * write the body to a file and return an empty one. Short-circuiting
	 * `pre_http_request` skips the transport that would have done the writing,
	 * so a fake that only returns a body leaves the caller with a file that
	 * exists and is empty. That is not a failure any test would think to
	 * arrange, and it looks exactly like a corrupt download.
	 *
	 * @param  array<string,mixed> $answer What this test arranged.
	 * @param  array<string,mixed> $args   How the plugin asked for it.
	 * @return array<string,mixed>
	 */
	private function deliver( array $answer, array $args ): array {
		$filename = isset( $args['filename'] ) ? (string) $args['filename'] : '';

		if ( empty( $args['stream'] ) || '' === $filename ) {
			return $answer;
		}

		// Writing the file is the transport's job, and this stands in for the
		// transport. WP_Filesystem is the wrong tool for a test double.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $filename, (string) $answer['body'] );

		$answer['body']     = '';
		$answer['filename'] = $filename;

		return $answer;
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
