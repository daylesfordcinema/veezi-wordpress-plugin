<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Clock;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The moment the front end treats as now.
 *
 * Small enough to look pointless, and is not: this is the seam that stops the
 * rest of the suite expiring. Every other test asserts against a fixed week in
 * August 2026, which is only still to come because {@see TestCase::set_up()}
 * pins this. If the filter stopped being honoured, those tests would go on
 * passing until the wall clock reached that week and then fail as a group, for
 * a reason that looks nothing like the cause.
 */
final class ClockTest extends TestCase {

	/**
	 * The base case, asserted without the suite's own pin in the way — this is
	 * the only test that wants the real clock, because it is the only one
	 * asserting what an unfiltered site does.
	 */
	public function test_it_reads_the_wall_clock_when_nothing_says_otherwise(): void {
		remove_all_filters( 'veezi_now' );

		$this->assertLessThanOrEqual( 1, abs( Clock::now() - time() ) );
	}

	public function test_a_filter_can_move_it(): void {
		$this->travel_to( '2026-08-02 09:30:00' );

		$this->assertSame( 1785663000, Clock::now() );
	}

	/**
	 * Whatever a filter hands back is used as an epoch second, so a string one
	 * has to arrive as an integer rather than as a fatal further down.
	 */
	public function test_it_is_a_number_whatever_a_filter_returns(): void {
		remove_all_filters( 'veezi_now' );
		add_filter( 'veezi_now', static fn (): string => '1785663000' );

		$this->assertSame( 1785663000, Clock::now() );
	}
}
