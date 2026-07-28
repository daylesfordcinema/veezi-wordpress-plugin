<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The order a listing comes out in, and when a screening stops being one.
 *
 * Everything here asserts what somebody choosing "Menu Order" from the loop
 * grid's sort dropdown gets. Repository's class docblock has the argument for
 * why that field and no other.
 */
final class OrderingTest extends TestCase {

	/**
	 * What the loop grid's "Menu Order" sort returns.
	 *
	 * @param  string $post_type Films or sessions.
	 * @return array<int,int>
	 */
	private function in_menu_order( string $post_type ): array {
		return get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => array_keys( get_post_stati() ),
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'fields'      => 'ids',
			)
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function films_in_menu_order(): array {
		return array_map( 'get_the_title', $this->in_menu_order( ContentModel::FILM ) );
	}

	/**
	 * @return array<int,int> Veezi session identifiers.
	 */
	private function sessions_in_menu_order(): array {
		return array_map(
			static fn ( int $id ): int => (int) get_post_meta( $id, ContentModel::SESSION_ID, true ),
			$this->in_menu_order( ContentModel::SESSION )
		);
	}

	/**
	 * A film each, with one screening apiece.
	 *
	 * Callers list them in an order deliberately unlike the order they screen
	 * in, because the order they are listed in is the order the sync creates
	 * the records in — which is exactly what a default sort would give.
	 *
	 * @param  array<string,string> $starts Film identifier to its session start.
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
	 *         The sessions and the films, ready to arrange.
	 */
	private function programme_of( array $starts ): array {
		$sessions = array();
		$films    = array();
		$id       = 0;

		foreach ( $starts as $film_id => $start ) {
			++$id;

			$sessions[] = $this->session_payload(
				array(
					'Id'     => $id,
					'FilmId' => $film_id,
					'starts' => $start,
				)
			);
			$films[]    = $this->film_payload(
				array(
					'Id'    => $film_id,
					'Title' => ucfirst( $film_id ),
				)
			);
		}

		return array( $sessions, $films );
	}

	public function test_films_come_out_in_the_order_they_next_screen(): void {
		list( $sessions, $films ) = $this->programme_of(
			array(
				'last'   => '2026-08-09T19:00:00',
				'first'  => '2026-08-02T16:30:00',
				'second' => '2026-08-05T19:00:00',
			)
		);

		$this->arrange_programme( $sessions, $films );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( array( 'First', 'Second', 'Last' ), $this->films_in_menu_order() );
	}

	public function test_sessions_come_out_chronologically(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 30,
						'starts' => '2026-08-04T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 10,
						'starts' => '2026-08-02T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 20,
						'starts' => '2026-08-03T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( array( 10, 20, 30 ), $this->sessions_in_menu_order() );
	}

	/**
	 * The case a rank written once and left alone would get wrong: nothing about
	 * the film that moves has changed except its place relative to the others.
	 */
	public function test_a_film_that_schedules_something_sooner_moves_up_the_order(): void {
		list( $sessions, $films ) = $this->programme_of(
			array(
				'first'  => '2026-08-02T16:30:00',
				'second' => '2026-08-05T19:00:00',
			)
		);

		$this->arrange_programme( $sessions, $films );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( array( 'First', 'Second' ), $this->films_in_menu_order() );

		$sessions[] = $this->session_payload(
			array(
				'Id'     => 99,
				'FilmId' => 'second',
				'starts' => '2026-08-01T11:00:00',
			)
		);

		$this->arrange_programme( $sessions, $films );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( array( 'Second', 'First' ), $this->films_in_menu_order() );
	}

	/**
	 * A rank is a position in a queue, not a moment in time. WordPress stores it
	 * in a signed 32-bit column, so a listing ranked by epoch second would work
	 * until 2038 and then quietly wrap — and ranking by anything finer than a
	 * second would have overflowed already.
	 */
	public function test_a_rank_is_a_position_and_not_a_timestamp(): void {
		list( $sessions, $films ) = $this->programme_of(
			array(
				'first'  => '2026-08-02T16:30:00',
				'second' => '2026-08-05T19:00:00',
				'third'  => '2026-08-09T19:00:00',
			)
		);

		$this->arrange_programme( $sessions, $films );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame(
			array( 1, 2, 3 ),
			array_map( static fn ( int $id ): int => (int) get_post_field( 'menu_order', $id ), $this->in_menu_order( ContentModel::FILM ) )
		);
		$this->assertSame(
			array( 1, 2, 3 ),
			array_map( static fn ( int $id ): int => (int) get_post_field( 'menu_order', $id ), $this->in_menu_order( ContentModel::SESSION ) )
		);
	}

	/**
	 * Deleting them at sync time is what lets a listing be "the next six
	 * sessions" with no date filter — which the loop grid could not express
	 * anyway, since its own date filter looks backwards and reads the published
	 * date.
	 */
	public function test_a_screening_that_has_finished_is_gone(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'Id' => 5 ) ) ),
			array( $this->film_payload() )
		);

		// 16:30 in Melbourne, plus ten minutes of advertisements and a hundred
		// minutes of film, finishes at 18:20 — which is 08:20 UTC.
		$this->sync_at( '2026-08-02 09:00:00' );

		$this->assertSame( array(), $this->records( ContentModel::SESSION ) );
	}

	/**
	 * Up to the moment it finishes, though. The margin is the film's own running
	 * time: somebody arriving late still wants to see that it is on, and a
	 * screening should not vanish from the website while an audience is sitting
	 * in it.
	 */
	public function test_a_screening_part_way_through_is_still_on(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'Id' => 5 ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-08-02 08:00:00' );

		$this->assertCount( 1, $this->records( ContentModel::SESSION ) );
		$this->assertSame(
			array( ContentModel::NOW_SHOWING ),
			wp_get_object_terms( $this->film_record( 'film-cook' ), ContentModel::LISTING, array( 'fields' => 'slugs' ) )
		);
	}

	/**
	 * The session half of the same case: a screening added to the middle of the
	 * week pushes everything after it down, and the records that were already
	 * there have to be rewritten to say so.
	 */
	public function test_a_screening_added_in_the_middle_renumbers_the_ones_after_it(): void {
		$sessions = array(
			$this->session_payload(
				array(
					'Id'     => 10,
					'starts' => '2026-08-02T16:30:00',
				)
			),
			$this->session_payload(
				array(
					'Id'     => 30,
					'starts' => '2026-08-06T16:30:00',
				)
			),
		);

		$this->arrange_programme( $sessions, array( $this->film_payload() ) );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( array( 10, 30 ), $this->sessions_in_menu_order() );

		$sessions[] = $this->session_payload(
			array(
				'Id'     => 20,
				'starts' => '2026-08-04T16:30:00',
			)
		);

		$this->arrange_programme( $sessions, array( $this->film_payload() ) );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( array( 10, 20, 30 ), $this->sessions_in_menu_order() );
		$this->assertSame(
			array( 1, 2, 3 ),
			array_map( static fn ( int $id ): int => (int) get_post_field( 'menu_order', $id ), $this->in_menu_order( ContentModel::SESSION ) )
		);
	}

	/**
	 * A film whose season ended keeps its rank until something says otherwise —
	 * and what it kept was position 1, which is now also the position of
	 * whatever is showing first. Any listing not filtered to the current
	 * programme would put last month's film at the top of it.
	 */
	public function test_a_film_whose_season_ended_ranks_below_the_ones_still_showing(): void {
		list( $sessions, $films ) = $this->programme_of( array( 'gone' => '2026-08-02T16:30:00' ) );

		$this->arrange_programme( $sessions, $films );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame( 1, (int) get_post_field( 'menu_order', $this->film_record( 'gone' ) ) );

		list( $later, $later_films ) = $this->programme_of(
			array(
				'showing' => '2026-09-05T16:30:00',
				'gone'    => '2026-08-02T16:30:00',
			)
		);

		$this->arrange_programme( $later, $later_films );
		$this->sync_at( '2026-09-01 00:00:00' );

		$this->assertSame( array( 'Showing', 'Gone' ), $this->films_in_menu_order() );
		$this->assertSame( 1, (int) get_post_field( 'menu_order', $this->film_record( 'showing' ) ) );
		$this->assertSame( 2, (int) get_post_field( 'menu_order', $this->film_record( 'gone' ) ) );
	}

	/**
	 * Yesterday's screening leaves; tomorrow's stays, and closes the gap so the
	 * ranks stay a run of positions rather than developing holes.
	 */
	public function test_finished_screenings_do_not_leave_gaps_in_the_order(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 1,
						'starts' => '2026-08-01T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-03T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 3,
						'starts' => '2026-08-04T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-08-02 00:00:00' );

		$this->assertSame( array( 2, 3 ), $this->sessions_in_menu_order() );
		$this->assertSame(
			array( 1, 2 ),
			array_map( static fn ( int $id ): int => (int) get_post_field( 'menu_order', $id ), $this->in_menu_order( ContentModel::SESSION ) )
		);
	}
}
