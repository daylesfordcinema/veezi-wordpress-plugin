<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Presentation\Screening;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The chronological listing: every screening still to come, whatever the film.
 *
 * Built by pointing the page builder's own loop widget at sessions and sorting
 * by the rank the sync maintains, so most of what this file tests is what such
 * a query comes back with — not any code of the plugin's that renders it.
 */
final class CalendarTest extends TestCase {

	/**
	 * The query a loop grid runs: published sessions, in rank order, with
	 * nothing else configured. That "nothing else" is the whole point of the
	 * rank, so a test that configured something would be testing a listing
	 * nobody is going to build.
	 *
	 * @return array<int,int>
	 */
	private function listing(): array {
		return get_posts(
			array(
				'post_type'   => ContentModel::SESSION,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => array( 'menu_order' => 'ASC' ),
			)
		);
	}

	/**
	 * Move a screening back to a quarter of an hour ago.
	 *
	 * The clock the sync runs on is the test's to choose, but the clock a page
	 * load reads is the real one — so a screening under way is arranged by
	 * moving the record rather than the moment.
	 *
	 * @param int $session A session record.
	 */
	private function has_begun( int $session ): void {
		update_post_meta( $session, ContentModel::SESSION_STARTS, (string) ( time() - 15 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Criterion: sessions that have already started never appear.
	 *
	 * The sync cannot do this on its own. It deletes a screening once it has
	 * *finished* — see {@see \Veezi\WordPress\Programme} — and it runs hourly,
	 * so between two runs a listing driven by nothing but the records would go
	 * on offering a screening the visitor has missed for up to an hour. The one
	 * thing worse than an empty listing is one selling the past.
	 */
	public function test_a_screening_that_has_started_is_gone_from_the_listing(): void {
		$this->veezi_is_showing( 2 );
		$this->sync_at();

		$begun     = $this->session_record( 2000 );
		$still_due = $this->session_record( 2001 );

		$this->has_begun( $begun );

		$this->assertSame( array( $still_due ), $this->listing() );
	}

	/**
	 * The listing is the only thing that hides it. The sync's own lookup asks
	 * for every record there is, and it must keep doing so: a screening it
	 * cannot find is one it creates a second copy of, which is a duplicate row
	 * in every listing and a second booking link for one screening.
	 */
	public function test_the_sync_still_finds_a_screening_that_has_started(): void {
		$this->veezi_is_showing( 2 );
		$this->sync_at();

		$this->has_begun( $this->session_record( 2000 ) );

		$this->sync_at();

		$this->assertCount( 2, $this->records( ContentModel::SESSION ) );
	}

	/**
	 * And a film's own list of times keeps it, which is the one deliberate
	 * difference between the two views.
	 *
	 * A card is about one film. Dropping the screening that is on right now
	 * makes the card say the film next screens tomorrow while an audience is
	 * sitting in it — so it stays, unbookable and marked, until it ends. The
	 * chronological listing is about choosing an evening, and a row nobody can
	 * get to or buy is noise at the top of today.
	 */
	public function test_a_films_own_times_keep_a_screening_that_has_begun(): void {
		$this->veezi_is_showing( 2 );
		$this->sync_at();

		$this->has_begun( $this->session_record( 2000 ) );

		$this->assertCount( 2, Screening::for_film( $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * Whether there is anything to list at all — which is the question behind
	 * the empty state, and has to be answered by the same rule the listing
	 * itself follows or the two disagree at exactly the wrong moment.
	 */
	public function test_nothing_is_upcoming_once_every_screening_has_started(): void {
		$this->veezi_is_showing( 2 );
		$this->sync_at();

		$this->assertTrue( Screening::any_upcoming() );

		$this->has_begun( $this->session_record( 2000 ) );
		$this->has_begun( $this->session_record( 2001 ) );

		$this->assertFalse( Screening::any_upcoming() );
	}

	/**
	 * One screening, at nine in the morning Melbourne time — which is the day
	 * before in UTC, and this site's timezone is UTC.
	 */
	private function a_morning_screening(): int {
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2036-08-02T09:00:00' ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		return $this->session_record( 1001 );
	}

	/**
	 * Criterion: a dynamic tag supplies each row's day heading.
	 *
	 * It is the time tag with a format of the designer's choosing rather than a
	 * tag of its own, because a second class differing from this one by a
	 * default string would be two names in the picker for one question. The
	 * same control gives the row its time underneath.
	 */
	public function test_a_row_takes_its_day_heading_from_the_time_tag(): void {
		$screening = $this->a_morning_screening();

		$this->assertSame(
			'Saturday 2 August',
			$this->bound( 'veezi-session-time', $screening, array( 'format' => 'l j F' ) )
		);
	}

	/**
	 * Criterion: day headings use the cinema's local timezone.
	 *
	 * The one that would go wrong silently. This screening is at 9am in
	 * Daylesford and at 11pm the day before in UTC, which is what this site's
	 * clock is set to — so a heading worked out from the site's timezone files
	 * the whole morning under the previous day, and the listing is a day out
	 * for every screening before ten.
	 */
	public function test_a_day_heading_is_the_cinemas_day_rather_than_the_sites(): void {
		$screening = $this->a_morning_screening();

		$this->assertSame( '0', get_option( 'gmt_offset' ), 'This test is only worth anything on a site keeping a different time.' );
		$this->assertSame( '2 August 2036', $this->bound( 'veezi-session-time', $screening, array( 'format' => 'j F Y' ) ) );
		$this->assertSame( '9:00 am', $this->bound( 'veezi-session-time', $screening, array( 'format' => 'g:i a' ) ) );
	}

	/**
	 * Left alone it reads exactly as it did before there was a control, which
	 * is the site's date and time. Templates already out there store no
	 * settings for this tag at all.
	 */
	public function test_a_time_left_unformatted_still_reads_the_way_the_site_writes_one(): void {
		$screening = $this->a_morning_screening();

		$this->assertSame( 'August 2, 2036 9:00 am', $this->bound( 'veezi-session-time', $screening ) );
	}

	/**
	 * Criterion: each row shows the film title.
	 *
	 * A session record's own title is the film and the time together — "The
	 * Cook's Tale — August 2, 2036 9:00 am" — because that is what makes it
	 * legible in the admin list. Binding the ordinary post title to a row would
	 * therefore print the time twice, once in a shape no control can change.
	 */
	public function test_a_row_binds_the_title_of_the_film_it_is_screening(): void {
		$screening = $this->a_morning_screening();

		$this->assertSame( 'The Cook’s Tale', $this->bound( 'veezi-film-title', $screening ) );
		$this->assertStringContainsString( '9:00 am', (string) get_post( $screening )?->post_title );
	}

	/**
	 * And on a film it is that film's own title, so a card and a row can be
	 * built the same way round.
	 */
	public function test_the_film_title_on_a_film_is_its_own(): void {
		$this->a_morning_screening();

		$this->assertSame( 'The Cook’s Tale', $this->bound( 'veezi-film-title', $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * Criterion: sold-out and nearly-sold-out states are visible.
	 *
	 * The row has no widget of the plugin's in it, so the badge the session
	 * times widget renders inside a card has to be bindable here as a field.
	 *
	 * @param        bool   $sold_out  What Veezi says about seats.
	 * @param        bool   $few_left  The same.
	 * @param        string $expected  What the row should read.
	 * @dataProvider seating
	 */
	public function test_a_row_says_when_a_screening_is_gone_or_nearly( bool $sold_out, bool $few_left, string $expected ): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'starts'         => '2036-08-02T09:00:00',
						'TicketsSoldOut' => $sold_out,
						'FewTicketsLeft' => $few_left,
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame( $expected, $this->bound( 'veezi-availability', $this->session_record( 1001 ) ) );
	}

	/**
	 * @return array<string,array{0:bool,1:bool,2:string}>
	 */
	public static function seating(): array {
		return array(
			'sold out'      => array( true, false, 'Sold out' ),
			'nearly gone'   => array( false, true, 'Few tickets left' ),
			'seats to sell' => array( false, false, '' ),

			// Veezi has been seen to send both at once, and sold out is the
			// one that stops somebody making a trip.
			'both at once'  => array( true, true, 'Sold out' ),
		);
	}

	/**
	 * The wording is the designer's, the same way it is on the widget — a
	 * cinema that says "Full house" should not need a translation file to say
	 * it.
	 */
	public function test_a_row_says_it_in_whatever_words_the_panel_chose(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'starts'         => '2036-08-02T09:00:00',
						'TicketsSoldOut' => true,
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame(
			'Full house',
			$this->bound(
				'veezi-availability',
				$this->session_record( 1001 ),
				array( 'sold_out_text' => 'Full house' )
			)
		);
	}

	/**
	 * Criterion: an explicit empty state, distinguishable from a failure.
	 *
	 * A loop grid with nothing in it renders nothing, and nothing is exactly
	 * what a broken token renders too. So the plugin supplies the sentence, and
	 * the tag holding it stays silent while there is a programme — a heading
	 * bound to it sits below the listing all year saying nothing.
	 */
	public function test_the_empty_state_says_nothing_while_there_is_a_programme(): void {
		$this->veezi_is_showing();
		$this->sync_at();

		$this->assertSame( '', $this->bound( 'veezi-nothing-scheduled', 0 ) );
	}

	/**
	 * A cinema between seasons is working perfectly and has nothing on. That is
	 * what a visitor is told.
	 */
	public function test_the_empty_state_speaks_when_there_is_nothing_on(): void {
		$this->arrange_programme( array(), array() );
		$this->sync_at();

		$this->assertSame(
			'There is nothing scheduled at the moment.',
			$this->bound( 'veezi-nothing-scheduled', 0 )
		);
	}

	public function test_the_empty_state_says_it_in_whatever_words_the_panel_chose(): void {
		$this->arrange_programme( array(), array() );
		$this->sync_at();

		$this->assertSame(
			'Back in the spring.',
			$this->bound( 'veezi-nothing-scheduled', 0, array( 'message' => 'Back in the spring.' ) )
		);
	}

	/**
	 * And the other half of "distinguishable from a failure": a site that has
	 * never synced looks identical to a cinema between seasons, and only one of
	 * them is something to go and fix. The person who can fix it is the one in
	 * the builder, so that is who is told — the visitor gets the sentence
	 * above, which is all they could act on either way.
	 */
	public function test_a_site_that_has_never_synced_says_so_to_whoever_is_building_it(): void {
		$this->in_the_editor();

		$this->assertSame(
			'No programme has synced yet. Check the connection under Settings → Veezi.',
			$this->bound( 'veezi-nothing-scheduled', 0 )
		);
	}

	public function test_a_visitor_is_never_told_about_the_connection(): void {
		$this->assertSame(
			'There is nothing scheduled at the moment.',
			$this->bound( 'veezi-nothing-scheduled', 0 )
		);
	}
}
