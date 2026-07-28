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
use Veezi\WordPress\SyncLock;
use Veezi\WordPress\SyncLog;
use Veezi\WordPress\SyncResult;
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

	/**
	 * A run that was allowed to happen.
	 *
	 * `attempt()` is the only door in, and it answers null when another run
	 * holds the lock. Nothing here arranges that, so a null would be a fault in
	 * its own right rather than the thing under test.
	 *
	 * @param DateTimeImmutable|null $moment When the run happens.
	 * @param string                 $token  The token it runs with.
	 */
	private function synced( ?DateTimeImmutable $moment = null, string $token = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ): SyncResult {
		$result = $this->sync( $token )->attempt( $moment );

		$this->assertNotNull( $result, 'The sync stood down: something left the lock held.' );

		return $result;
	}

	public function test_a_sync_uses_the_time_it_is_given(): void {
		$this->arrange_programme( array(), array() );

		$moment = new DateTimeImmutable( '2001-02-03 04:05:06', new DateTimeZone( 'UTC' ) );

		$result = $this->synced( $moment );

		$this->assertSame( $moment->getTimestamp(), $result->started_at()->getTimestamp() );
	}

	public function test_a_sync_given_no_time_uses_the_real_clock(): void {
		$this->arrange_programme( array(), array() );

		$result = $this->synced();

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

		$result = $this->synced();

		$this->assertSame( 0, $result->started_at()->getOffset() );
	}

	public function test_a_sync_reports_which_cinema_it_connected_to(): void {
		$this->arrange_programme( array(), array() );
		$this->veezi->will_return( '/v1/site', $this->site_payload( array( 'Name' => 'The Roxy, Marysville' ) ) );

		$result = $this->synced();

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString(
			'The Roxy, Marysville',
			$result->message(),
			'The name has to come from the payload, or this proves nothing.'
		);
	}

	public function test_a_sync_with_no_token_fails_without_reaching_the_network(): void {
		$result = ( new Sync( new Client( Token::resolve( new Settings() ) ) ) )->attempt();

		$this->assertNotNull( $result );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( array(), $this->veezi->requests );
	}

	public function test_a_sync_fails_when_veezi_is_unreachable(): void {
		$this->veezi->will_fail( '/v1/site' );

		$result = $this->synced();

		$this->assertFalse( $result->is_success() );
		$this->assertNotSame( '', $result->message(), 'A failure nobody can read is a failure nobody can fix.' );
	}

	public function test_a_failed_sync_still_records_when_it_was_attempted(): void {
		$this->veezi->will_fail( '/v1/site' );

		$moment = new DateTimeImmutable( '2001-02-03 04:05:06', new DateTimeZone( 'UTC' ) );

		$result = $this->synced( $moment );

		$this->assertSame( $moment->getTimestamp(), $result->started_at()->getTimestamp() );
	}

	public function test_a_failed_sync_does_not_leak_the_token_into_its_message(): void {
		$secret = 'LEAKYTOKEN0123456789ABCDEF';
		$this->veezi->will_fail( '/v1/site', "Failed connecting with {$secret}" );

		$result = $this->synced( null, $secret );

		$this->assertStringNotContainsString( $secret, $result->message() );
	}

	public function test_a_run_writes_down_what_it_did(): void {
		$this->arrange_programme( array(), array() );

		$this->synced( new DateTimeImmutable( '2026-07-28 03:00:00', new DateTimeZone( 'UTC' ) ) );

		$recorded = SyncLog::last_success();

		$this->assertNotNull( $recorded, 'A run nobody recorded is a run nobody can be told about.' );
		$this->assertSame( strtotime( '2026-07-28 03:00:00 UTC' ), $recorded->started_at()->getTimestamp() );
	}

	public function test_a_run_that_fails_writes_that_down_too(): void {
		$this->veezi->will_fail( '/v1/site' );

		$this->synced();

		$this->assertNotNull( SyncLog::unresolved_failure() );
		$this->assertFalse( SyncLog::has_ever_succeeded() );
	}

	public function test_a_guarded_run_syncs_like_any_other(): void {
		$this->arrange_programme( array(), array() );

		$result = $this->sync()->attempt();

		$this->assertNotNull( $result );
		$this->assertTrue( $result->is_success() );
	}

	/**
	 * Turned away rather than queued: by the time this run could start, the one
	 * already going will have fetched the same programme.
	 */
	public function test_a_guarded_run_stands_down_while_another_is_going(): void {
		$this->arrange_programme( array(), array() );
		SyncLock::acquire();

		$this->assertNull( $this->sync()->attempt() );
		$this->assertSame( array(), $this->veezi->requests, 'A run that stood down must not have talked to Veezi.' );
	}

	/**
	 * Standing aside is not a fault, and an administrator told "sync failed"
	 * because two firings overlapped would go looking for a problem that was
	 * never there.
	 */
	public function test_standing_down_is_not_recorded_as_a_failure(): void {
		$this->arrange_programme( array(), array() );
		SyncLock::acquire();

		$this->sync()->attempt();

		$this->assertNull( SyncLog::unresolved_failure() );
	}

	public function test_a_guarded_run_hands_the_lock_back_afterwards(): void {
		$this->arrange_programme( array(), array() );

		$this->sync()->attempt();

		$this->assertNotNull( SyncLock::acquire(), 'A run that keeps the lock stops the site syncing until it expires.' );
	}

	public function test_a_guarded_run_hands_the_lock_back_even_when_it_fails(): void {
		$this->veezi->will_fail( '/v1/site' );

		$this->sync()->attempt();

		$this->assertNotNull( SyncLock::acquire() );
	}
}
