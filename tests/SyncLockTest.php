<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\SyncLock;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Only one sync at a time.
 *
 * Two runs overlapping is not hypothetical here: a sync that has posters to
 * fetch can outlast the interval between two cron firings, and an administrator
 * pressing "Sync now" while one is already going is a click away. Both would
 * have two processes writing the same records and downloading the same artwork.
 */
final class SyncLockTest extends TestCase {

	public function test_a_free_lock_can_be_taken(): void {
		$this->assertNotNull( SyncLock::acquire() );
	}

	public function test_a_second_run_cannot_take_a_lock_somebody_is_holding(): void {
		SyncLock::acquire();

		$this->assertNull( SyncLock::acquire(), 'The second sync must be turned away, not queued behind the first.' );
	}

	public function test_releasing_lets_the_next_run_in(): void {
		$held = SyncLock::acquire();

		$this->assertNotNull( $held );
		$held->release();

		$this->assertNotNull( SyncLock::acquire() );
	}

	/**
	 * A sync killed halfway — a PHP timeout, a restarted container — never
	 * reaches its release. Without an expiry that site never syncs again, and
	 * the only symptom is a programme that quietly stops changing.
	 */
	public function test_a_lock_left_behind_by_a_dead_run_expires(): void {
		SyncLock::acquire( $this->long_enough_ago() );

		$this->assertNotNull( SyncLock::acquire(), 'A lock older than a run can possibly be is nobody holding it.' );
	}

	/**
	 * Taking over an abandoned lock has to leave it *held*. Clearing it and
	 * then failing to claim it would let every waiting run in at once, at the
	 * exact moment the site is already in trouble.
	 */
	public function test_taking_over_an_abandoned_lock_leaves_it_held(): void {
		SyncLock::acquire( $this->long_enough_ago() );

		$this->assertNotNull( SyncLock::acquire() );
		$this->assertNull( SyncLock::acquire() );
	}

	/**
	 * The run declared dead can still be alive enough to reach its own release.
	 * If it frees the lock its successor is holding, two syncs are running and
	 * a third can start — which is the one way this class fails at its job.
	 */
	public function test_a_superseded_run_cannot_free_its_successors_lock(): void {
		$stalled = SyncLock::acquire( $this->long_enough_ago() );

		$this->assertNotNull( $stalled );
		$this->assertNotNull( SyncLock::acquire(), 'The next run takes over.' );

		$stalled->release();

		$this->assertNull( SyncLock::acquire(), 'The dead run gave away a lock that was no longer its own.' );
	}

	public function test_releasing_a_lock_twice_is_harmless(): void {
		$held = SyncLock::acquire();

		$this->assertNotNull( $held );
		$held->release();
		$held->release();

		$this->assertNotNull( SyncLock::acquire() );
	}

	/**
	 * Long enough ago that a run which took the lock then is presumed dead —
	 * the one state a suite cannot reach by waiting for it.
	 */
	private function long_enough_ago(): int {
		return time() - SyncLock::EXPIRES_AFTER - 60;
	}
}
