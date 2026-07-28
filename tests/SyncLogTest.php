<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\SyncLog;
use Veezi\WordPress\SyncResult;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * What the last run did, and when.
 *
 * This is the answer to two questions an administrator asks from opposite
 * directions: "can I trust what is on the site" and "is something wrong". They
 * are not the same question, which is why a success and a failure are kept
 * apart rather than one overwriting the other.
 */
final class SyncLogTest extends TestCase {

	private function moment( string $moment ): DateTimeImmutable {
		return new DateTimeImmutable( $moment, new DateTimeZone( 'UTC' ) );
	}

	public function test_a_site_that_has_never_synced_has_nothing_to_report(): void {
		$this->assertFalse( SyncLog::has_ever_succeeded() );
		$this->assertNull( SyncLog::last_success() );
		$this->assertNull( SyncLog::unresolved_failure() );
	}

	public function test_a_successful_run_records_when_it_happened_and_what_it_did(): void {
		SyncLog::record(
			SyncResult::completed( $this->moment( '2026-07-28 03:00:00' ), 'Synced 9 films and 32 sessions from Phoenix Cinema.' )
		);

		$success = SyncLog::last_success();

		$this->assertNotNull( $success );
		$this->assertTrue( SyncLog::has_ever_succeeded() );
		$this->assertSame( $this->moment( '2026-07-28 03:00:00' )->getTimestamp(), $success->started_at()->getTimestamp() );
		$this->assertSame( 'Synced 9 films and 32 sessions from Phoenix Cinema.', $success->message() );
	}

	public function test_a_failure_is_kept_so_that_somebody_can_be_told_about_it(): void {
		SyncLog::record(
			SyncResult::failed( $this->moment( '2026-07-28 04:00:00' ), 'Veezi refused the access token.' )
		);

		$failure = SyncLog::unresolved_failure();

		$this->assertNotNull( $failure );
		$this->assertSame( 'Veezi refused the access token.', $failure->message() );
		$this->assertSame( $this->moment( '2026-07-28 04:00:00' )->getTimestamp(), $failure->started_at()->getTimestamp() );
	}

	/**
	 * The programme on the site is whatever the last *successful* run put
	 * there, and that stays true through an outage. An administrator reading
	 * "last synced: never" during a bad hour would conclude the site is empty
	 * when it is serving the programme perfectly well.
	 */
	public function test_a_failure_leaves_the_record_of_the_last_success_alone(): void {
		SyncLog::record( SyncResult::completed( $this->moment( '2026-07-28 03:00:00' ), 'Synced 9 films.' ) );
		SyncLog::record( SyncResult::failed( $this->moment( '2026-07-28 04:00:00' ), 'Could not reach Veezi.' ) );

		$success = SyncLog::last_success();

		$this->assertNotNull( $success );
		$this->assertSame( 'Synced 9 films.', $success->message() );
		$this->assertTrue( SyncLog::has_ever_succeeded() );
	}

	/**
	 * A failure that has been recovered from is not a failure anybody needs
	 * telling about, so the notice it raises has to put itself away. Nothing
	 * dismisses it: the next run that works does.
	 */
	public function test_a_run_that_works_clears_the_failure_before_it(): void {
		SyncLog::record( SyncResult::failed( $this->moment( '2026-07-28 04:00:00' ), 'Could not reach Veezi.' ) );
		SyncLog::record( SyncResult::completed( $this->moment( '2026-07-28 05:00:00' ), 'Synced 9 films.' ) );

		$this->assertNull( SyncLog::unresolved_failure() );
	}

	public function test_the_most_recent_failure_is_the_one_reported(): void {
		SyncLog::record( SyncResult::failed( $this->moment( '2026-07-28 04:00:00' ), 'Could not reach Veezi.' ) );
		SyncLog::record( SyncResult::failed( $this->moment( '2026-07-28 05:00:00' ), 'Veezi refused the access token.' ) );

		$failure = SyncLog::unresolved_failure();

		$this->assertNotNull( $failure );
		$this->assertSame( 'Veezi refused the access token.', $failure->message() );
	}

	/**
	 * The notice tells the cinema; the log tells whoever has to fix it. On a
	 * production site WP_DEBUG is off, so gating this on it would mean no
	 * record at all in the one place a record matters.
	 */
	public function test_a_failure_leaves_something_in_the_php_log_to_diagnose_it_from(): void {
		SyncLog::record(
			SyncResult::failed( $this->moment( '2026-07-28 04:00:00' ), 'Veezi answered with an unexpected status (503).' )
		);

		$logged = $this->logged();

		$this->assertStringContainsString( 'Veezi answered with an unexpected status (503).', $logged );
		$this->assertStringContainsString( 'Veezi', $logged, 'A line nobody can attribute to this plugin is not diagnosable.' );
	}

	/**
	 * A cinema's programme syncs every hour of every day. A line per run would
	 * bury the one line that matters under thousands that do not.
	 */
	public function test_a_working_sync_says_nothing_to_the_log(): void {
		SyncLog::record( SyncResult::completed( $this->moment( '2026-07-28 03:00:00' ), 'Synced 9 films.' ) );

		$this->assertSame( '', trim( $this->logged() ) );
	}

	public function test_forgetting_leaves_no_trace_of_either(): void {
		SyncLog::record( SyncResult::completed( $this->moment( '2026-07-28 03:00:00' ), 'Synced 9 films.' ) );
		SyncLog::record( SyncResult::failed( $this->moment( '2026-07-28 04:00:00' ), 'Could not reach Veezi.' ) );

		SyncLog::forget();

		$this->assertFalse( SyncLog::has_ever_succeeded() );
		$this->assertNull( SyncLog::last_success() );
		$this->assertNull( SyncLog::unresolved_failure() );
	}
}
