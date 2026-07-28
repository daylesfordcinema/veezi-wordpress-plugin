<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Client;
use Veezi\WordPress\ResponseCache;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Token;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Not asking Veezi the same question twice in a minute.
 *
 * Veezi publishes no rate limits and marks every response uncacheable, so how
 * often this plugin calls is entirely the plugin's own restraint. The heavy
 * lifting is done elsewhere — the site renders from WordPress content and never
 * calls the API to draw a page — and this is what covers the bursts that
 * remain.
 */
final class ResponseCacheTest extends TestCase {

	private function client( string $token = self::TOKEN ): Client {
		$this->store_token( $token );

		return new Client( Token::resolve( new Settings() ) );
	}

	public function test_the_same_question_twice_running_is_only_asked_once(): void {
		$this->veezi->will_return( Client::FILMS, array( $this->film_payload() ) );

		$client = $this->client();
		$client->get( Client::FILMS );
		$client->get( Client::FILMS );

		$this->assertSame( 1, $this->requests_to( Client::FILMS ) );
	}

	public function test_the_cached_answer_is_the_answer(): void {
		$this->veezi->will_return( Client::FILMS, array( $this->film_payload() ) );

		$client = $this->client();
		$first  = $client->get( Client::FILMS );

		$this->assertSame( $first, $client->get( Client::FILMS ) );
	}

	public function test_two_endpoints_do_not_share_an_answer(): void {
		$this->veezi->will_return( Client::FILMS, array( $this->film_payload() ) );
		$this->veezi->will_return( Client::SESSIONS, array( $this->session_payload() ) );

		$client = $this->client();

		$this->assertArrayHasKey( 'Title', $client->get( Client::FILMS )[0] );
		$this->assertArrayHasKey( 'ScreenId', $client->get( Client::SESSIONS )[0] );
	}

	/**
	 * Caching an outage would extend it: the minute Veezi comes back, the site
	 * would go on serving the failure for the rest of the window.
	 */
	public function test_a_failure_is_never_remembered(): void {
		$this->veezi->will_fail( Client::FILMS );

		$client = $this->client();

		$this->assertWPError( $client->get( Client::FILMS ) );

		$this->veezi->will_return( Client::FILMS, array( $this->film_payload() ) );
		$recovered = $client->get( Client::FILMS );

		$this->assertSame( 2, $this->requests_to( Client::FILMS ), 'The second question has to reach Veezi.' );
		$this->assertSame( 'The Cook’s Tale', $recovered[0]['Title'] ?? '' );
	}

	/**
	 * A connection check exists to ask Veezi *now* — after revoking a token,
	 * say. An answer from four minutes ago would say the credential works when
	 * it no longer does, which is the one thing this screen must never do.
	 */
	public function test_a_connection_check_always_asks(): void {
		$this->veezi->will_return( Client::SITE, $this->site_payload() );

		$client = $this->client();
		$client->check_connection();
		$client->check_connection();

		$this->assertSame( 2, $this->requests_to( Client::SITE ) );
	}

	/**
	 * Whose programme this is, is part of the question. A site repointed at a
	 * different cinema's account must not be answered with the last one's.
	 */
	public function test_a_different_token_is_a_different_question(): void {
		$this->veezi->will_return( Client::FILMS, array( $this->film_payload() ) );

		$this->client( 'FIRSTTOKEN0123456789ABCDEF' )->get( Client::FILMS );
		$this->client( 'SECONDTOKEN123456789ABCDEF' )->get( Client::FILMS );

		$this->assertSame( 2, $this->requests_to( Client::FILMS ) );
	}

	public function test_forgetting_sends_the_next_question_upstream(): void {
		$this->veezi->will_return( Client::FILMS, array( $this->film_payload() ) );

		$client = $this->client();
		$client->get( Client::FILMS );

		ResponseCache::forget();
		$client->get( Client::FILMS );

		$this->assertSame( 2, $this->requests_to( Client::FILMS ) );
	}
}
