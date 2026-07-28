<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\Client;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Sync;
use Veezi\WordPress\Token;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The sync entry point.
 *
 * At this stage the sync does one thing — authenticate, and find out whose
 * programme it is about to read. What is established here is the shape the
 * rest hangs off: a clock the caller can supply, so that horizon boundaries
 * and past-session cutoffs are decidable rather than dependent on when the
 * suite happened to run.
 */
final class SyncTest extends TestCase {

	private function sync( string $token = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ): Sync {
		$this->store_token( $token );

		return new Sync( new Client( Token::resolve( new Settings() ) ) );
	}

	public function test_a_sync_uses_the_time_it_is_given(): void {
		$this->arrange_programme( array(), array() );

		$moment = new DateTimeImmutable( '2001-02-03 04:05:06', new DateTimeZone( 'UTC' ) );

		$result = $this->sync()->run( $moment );

		$this->assertSame( $moment->getTimestamp(), $result->started_at()->getTimestamp() );
	}

	public function test_a_sync_given_no_time_uses_the_real_clock(): void {
		$this->arrange_programme( array(), array() );

		$result = $this->sync()->run();

		$this->assertLessThanOrEqual(
			2,
			abs( $result->started_at()->getTimestamp() - time() ),
			'Left to itself the sync must run against now, not against a fixture.'
		);
	}

	/**
	 * Veezi reports times with no offset, so every date decision the sync
	 * makes depends on a zone it chose deliberately. Defaulting to the
	 * server's would make the same code behave differently on two hosts.
	 */
	public function test_the_default_clock_is_unambiguous_about_its_timezone(): void {
		$this->arrange_programme( array(), array() );

		$result = $this->sync()->run();

		$this->assertSame( 0, $result->started_at()->getOffset() );
	}

	public function test_a_sync_reports_which_cinema_it_connected_to(): void {
		$this->arrange_programme( array(), array() );
		$this->veezi->will_return( '/v1/site', $this->site_payload( array( 'Name' => 'The Roxy, Marysville' ) ) );

		$result = $this->sync()->run();

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString(
			'The Roxy, Marysville',
			$result->message(),
			'The name has to come from the payload, or this proves nothing.'
		);
	}

	public function test_a_sync_with_no_token_fails_without_reaching_the_network(): void {
		$result = ( new Sync( new Client( Token::resolve( new Settings() ) ) ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( array(), $this->veezi->requests );
	}

	public function test_a_sync_fails_when_veezi_is_unreachable(): void {
		$this->veezi->will_fail( '/v1/site' );

		$result = $this->sync()->run();

		$this->assertFalse( $result->is_success() );
		$this->assertNotSame( '', $result->message(), 'A failure nobody can read is a failure nobody can fix.' );
	}

	public function test_a_failed_sync_still_records_when_it_was_attempted(): void {
		$this->veezi->will_fail( '/v1/site' );

		$moment = new DateTimeImmutable( '2001-02-03 04:05:06', new DateTimeZone( 'UTC' ) );

		$result = $this->sync()->run( $moment );

		$this->assertSame( $moment->getTimestamp(), $result->started_at()->getTimestamp() );
	}

	public function test_a_failed_sync_does_not_leak_the_token_into_its_message(): void {
		$secret = 'LEAKYTOKEN0123456789ABCDEF';
		$this->veezi->will_fail( '/v1/site', "Failed connecting with {$secret}" );

		$result = $this->sync( $secret )->run();

		$this->assertStringNotContainsString( $secret, $result->message() );
	}
}
