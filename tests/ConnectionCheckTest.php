<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Client;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Token;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * What an administrator learns from pressing "Test connection".
 *
 * The distinction the ticket turns on is between *we were refused* and *we
 * could not reach them*: the first is the administrator's problem to fix and
 * the second is not, and telling them apart is the difference between a
 * five-minute fix and an afternoon.
 */
final class ConnectionCheckTest extends TestCase {

	private function client( string $token = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ): Client {
		$this->store_token( $token );

		return new Client( Token::resolve( new Settings() ) );
	}

	public function test_a_working_connection_reports_the_cinema_name(): void {
		$this->veezi->will_return( '/v1/site', $this->site_payload( array( 'Name' => 'Regal Picture Palace' ) ) );

		$result = $this->client()->check_connection();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Regal Picture Palace', $result->site_name() );
	}

	public function test_a_rejected_token_is_reported_as_a_credentials_problem(): void {
		$this->veezi->will_return( '/v1/site', '', 403 );

		$result = $this->client()->check_connection();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( Client::ERROR_REJECTED, $result->code() );
	}

	public function test_an_unauthorised_response_is_also_a_credentials_problem(): void {
		$this->veezi->will_return( '/v1/site', '', 401 );

		$this->assertSame( Client::ERROR_REJECTED, $this->client()->check_connection()->code() );
	}

	/**
	 * The API sits behind a CDN whose bot and rate-limit checks also answer
	 * 403, with a challenge page. Calling that a bad token sends an
	 * administrator off to replace a credential that was never the problem.
	 */
	public function test_a_security_challenge_is_not_reported_as_a_bad_token(): void {
		$this->veezi->will_return(
			'/v1/site',
			'<!DOCTYPE html><html><head><title>Just a moment...</title></head></html>',
			403
		);

		$result = $this->client()->check_connection();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( Client::ERROR_BLOCKED, $result->code() );
		$this->assertNotSame( Client::ERROR_REJECTED, $result->code() );
	}

	public function test_a_genuine_rejection_is_still_reported_as_a_bad_token(): void {
		// What Veezi itself answers: 403, and nothing in the body.
		$this->veezi->will_return( '/v1/site', '', 403 );

		$this->assertSame( Client::ERROR_REJECTED, $this->client()->check_connection()->code() );
	}

	/**
	 * Arranging `/v1/site` must not also answer `/v1/sitegroup`. Veezi really
	 * does have endpoints that are prefixes of each other — `/v1/film` and
	 * `/v1/filmpackage` — so a substring match would quietly feed the wrong
	 * fixture to a later test and make it pass for the wrong reason.
	 *
	 * The exception comes from the suite's own guard: an unarranged request is
	 * an error rather than a trip to the real API.
	 */
	public function test_an_endpoint_is_not_confused_with_one_whose_name_extends_it(): void {
		$this->veezi->will_return( '/v1/site', $this->site_payload() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unintercepted outbound HTTP request' );

		$this->client()->get( '/v1/sitegroup' );
	}

	/**
	 * An outage at Veezi is not a configuration mistake, and an administrator
	 * told "check your token" will spend the afternoon replacing a token that
	 * was fine.
	 */
	public function test_an_unreachable_service_is_not_reported_as_a_bad_token(): void {
		$this->veezi->will_fail( '/v1/site', 'Could not resolve host: api.oz.veezi.com' );

		$result = $this->client()->check_connection();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( Client::ERROR_UNREACHABLE, $result->code() );
		$this->assertNotSame( Client::ERROR_REJECTED, $result->code() );
	}

	public function test_a_server_error_is_not_reported_as_a_bad_token(): void {
		$this->veezi->will_return( '/v1/site', 'Internal Server Error', 500 );

		$result = $this->client()->check_connection();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( Client::ERROR_UNEXPECTED_STATUS, $result->code() );
	}

	public function test_a_response_that_is_not_json_is_reported_as_unexpected(): void {
		$this->veezi->will_return( '/v1/site', '<html>Just a moment…</html>' );

		$result = $this->client()->check_connection();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( Client::ERROR_MALFORMED, $result->code() );
	}

	public function test_a_response_missing_the_cinema_name_is_reported_as_unexpected(): void {
		$this->veezi->will_return( '/v1/site', array( 'ShortName' => 'Regal' ) );

		$this->assertSame( Client::ERROR_MALFORMED, $this->client()->check_connection()->code() );
	}

	public function test_no_request_is_made_when_no_token_is_configured(): void {
		$result = ( new Client( Token::resolve( new Settings() ) ) )->check_connection();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( Client::ERROR_NO_TOKEN, $result->code() );
		$this->assertSame( array(), $this->veezi->requests, 'Nothing to authenticate with, so nothing to ask.' );
	}

	public function test_the_token_is_sent_in_the_header_veezi_expects(): void {
		$this->veezi->will_return( '/v1/site', $this->site_payload() );

		$this->client( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' )->check_connection();

		$request = $this->veezi->last_request_to( '/v1/site' );

		$this->assertNotNull( $request );
		$this->assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', $request['args']['headers']['VeeziAccessToken'] );
	}

	/**
	 * Veezi rejects a token supplied any other way, so getting this wrong
	 * presents as an authentication failure with a correct token.
	 */
	public function test_the_token_is_never_put_in_the_url(): void {
		$this->veezi->will_return( '/v1/site', $this->site_payload() );

		$this->client( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' )->check_connection();

		$this->assertStringNotContainsString(
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
			$this->veezi->last_request_to( '/v1/site' )['url']
		);
	}

	/**
	 * Failure messages are shown in the admin and written to the log. A token
	 * in either is a leaked credential.
	 */
	public function test_no_failure_message_contains_the_token(): void {
		$secret = 'LEAKYTOKEN0123456789ABCDEF';

		$failures = array(
			'rejected'    => fn() => $this->veezi->will_return( '/v1/site', 'Forbidden: token LEAKYTOKEN0123456789ABCDEF', 403 ),
			'unreachable' => fn() => $this->veezi->will_fail( '/v1/site', 'Failed to connect using LEAKYTOKEN0123456789ABCDEF' ),
			'malformed'   => fn() => $this->veezi->will_return( '/v1/site', 'LEAKYTOKEN0123456789ABCDEF' ),
		);

		foreach ( $failures as $name => $arrange ) {
			$arrange();

			$result = $this->client( $secret )->check_connection();

			$this->assertStringNotContainsString( $secret, $result->message(), "Leaked while {$name}." );
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $result ), "Leaked while {$name}." );
		}
	}

	public function test_the_region_endpoint_can_be_pointed_elsewhere(): void {
		$filter = static fn(): string => 'https://api.uk.veezi.com';
		add_filter( 'veezi_api_base_url', $filter );

		$this->veezi->will_return( '/v1/site', $this->site_payload() );
		$this->client()->check_connection();

		$this->assertStringStartsWith(
			'https://api.uk.veezi.com/',
			$this->veezi->last_request_to( '/v1/site' )['url'],
			'Veezi issues an account against one region, and not every cinema is in this one.'
		);
	}
}
