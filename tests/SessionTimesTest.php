<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The one widget the plugin owns, and the one thing dynamic tags cannot do.
 *
 * A card showing every time a film screens this week is a list inside a list.
 * The builder's loop widget cannot nest, and a dynamic tag can offer only one
 * value, so no arrangement of the two produces it — which is why this exists at
 * all, rather than as a convenience.
 *
 * Everything here asserts what ends up on the page, not how the widget is put
 * together. The times are asserted as literal strings in the HTML, which is
 * also what pins them as text a screen reader can read rather than pictures of
 * numbers.
 */
final class SessionTimesTest extends TestCase {

	private const WIDGET = 'veezi-session-times';

	/**
	 * A week of screenings for one film, at times that read distinctly.
	 *
	 * @param array<int,string> $starts Veezi session id to its start time.
	 * @param array<int,mixed>  $extra  Further sessions, already built.
	 */
	private function screenings( array $starts, array $extra = array() ): void {
		$sessions = $extra;

		foreach ( $starts as $id => $start ) {
			$sessions[] = $this->session_payload(
				array(
					'Id'     => $id,
					'starts' => $start,
				)
			);
		}

		$this->arrange_programme( $sessions, array( $this->film_payload() ) );
		$this->sync_at();
	}

	private function cook(): int {
		return $this->film_record( 'film-cook' );
	}

	public function test_a_card_lists_every_screening_still_to_come(): void {
		$this->screenings(
			array(
				10 => '2026-08-02T16:30:00',
				20 => '2026-08-04T19:15:00',
				30 => '2026-08-06T20:45:00',
			)
		);

		$html = $this->rendered_widget( self::WIDGET, $this->cook(), array( 'time_format' => 'H:i' ) );

		foreach ( array( '16:30', '19:15', '20:45' ) as $time ) {
			$this->assertStringContainsString( $time, $html );
		}

		$this->assertLessThan( strpos( $html, '19:15' ), strpos( $html, '16:30' ) );
		$this->assertLessThan( strpos( $html, '20:45' ), strpos( $html, '19:15' ) );
	}

	/**
	 * Every row is a link to that particular screening, which is the whole point
	 * of the card: a visitor picks a time and lands on the page selling seats
	 * for it, rather than on a listing they have to search again.
	 */
	public function test_each_time_links_to_that_particular_screening(): void {
		$this->screenings(
			array(
				10 => '2026-08-02T16:30:00',
				20 => '2026-08-04T19:15:00',
			)
		);

		$html = $this->rendered_widget( self::WIDGET, $this->cook() );

		$this->assertStringContainsString( 'href="https://ticketing.example.test/purchase?session=10"', $html );
		$this->assertStringContainsString( 'href="https://ticketing.example.test/purchase?session=20"', $html );
	}

	/**
	 * Inside a loop grid there is nothing naming the film. The widget shows
	 * whichever one it is being rendered for, which is also what makes a
	 * duplicated template behave like the one it was copied from.
	 */
	public function test_it_lists_the_film_it_is_being_rendered_for(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 10,
						'starts' => '2026-08-02T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 20,
						'FilmId' => 'film-lighthouse',
						'Title'  => 'The Lighthouse Keeper',
						'starts' => '2026-08-04T19:15:00',
					)
				),
			),
			array(
				$this->film_payload(),
				$this->film_payload(
					array(
						'Id'    => 'film-lighthouse',
						'Title' => 'The Lighthouse Keeper',
					)
				),
			)
		);

		$this->sync_at();

		$html = $this->rendered_widget( self::WIDGET, $this->cook(), array( 'time_format' => 'H:i' ) );

		$this->assertStringContainsString( '16:30', $html );
		$this->assertStringNotContainsString( '19:15', $html );
	}

	/**
	 * A sold-out screening still belongs on the card — somebody scanning the
	 * week needs to know it is on and gone rather than wondering whether they
	 * misread the listing. What it must not have is a button, because a click
	 * that lands on "no seats available" is a wasted trip.
	 */
	public function test_a_sold_out_screening_is_marked_and_offers_no_link(): void {
		$this->screenings(
			array(),
			array(
				$this->session_payload(
					array(
						'Id'             => 10,
						'starts'         => '2026-08-02T16:30:00',
						'TicketsSoldOut' => true,
					)
				),
			)
		);

		$html = $this->rendered_widget( self::WIDGET, $this->cook(), array( 'time_format' => 'H:i' ) );

		$this->assertStringContainsString( '16:30', $html );
		$this->assertStringContainsString( 'Sold out', $html );
		$this->assertStringNotContainsString( 'href=', $html );
	}

	public function test_a_screening_with_few_tickets_left_says_so_and_still_sells(): void {
		$this->screenings(
			array(),
			array(
				$this->session_payload(
					array(
						'Id'             => 10,
						'starts'         => '2026-08-02T16:30:00',
						'FewTicketsLeft' => true,
					)
				),
			)
		);

		$html = $this->rendered_widget( self::WIDGET, $this->cook() );

		$this->assertStringContainsString( 'Few tickets left', $html );
		$this->assertStringContainsString( 'href="https://ticketing.example.test/purchase?session=10"', $html );
	}

	/**
	 * Both badges are a control rather than a string in the code, because the
	 * cinema's own voice is not the plugin's to decide — and because a site in
	 * another language needs to change them without a developer.
	 */
	public function test_what_a_badge_says_is_the_designers_to_choose(): void {
		$this->screenings(
			array(),
			array(
				$this->session_payload(
					array(
						'Id'             => 10,
						'starts'         => '2026-08-02T16:30:00',
						'TicketsSoldOut' => true,
					)
				),
			)
		);

		$html = $this->rendered_widget(
			self::WIDGET,
			$this->cook(),
			array( 'sold_out_text' => 'All booked out' )
		);

		$this->assertStringContainsString( 'All booked out', $html );
		$this->assertStringNotContainsString( 'Sold out', $html );
	}

	/**
	 * The times are worked out again from the instant rather than reprinted from
	 * what the sync stored, which is what lets the format be a control at all.
	 * It has to be the cinema's clock: this site's timezone is UTC, ten hours
	 * behind, and reading 06:30 would send everyone to a screening that finished
	 * before they set out.
	 */
	public function test_times_are_the_cinemas_own_however_they_are_formatted(): void {
		$this->screenings( array( 10 => '2026-08-02T16:30:00' ) );

		$this->assertStringContainsString(
			'16:30',
			$this->rendered_widget( self::WIDGET, $this->cook(), array( 'time_format' => 'H:i' ) )
		);
		$this->assertStringContainsString(
			'4.30pm',
			$this->rendered_widget( self::WIDGET, $this->cook(), array( 'time_format' => 'g.i\p\m' ) )
		);
	}

	/**
	 * A card for a film screening across a fortnight needs the day as well, and
	 * one showing tonight's three times does not. Which of the two is a control.
	 */
	public function test_a_row_can_be_asked_to_name_its_day(): void {
		$this->screenings( array( 10 => '2026-08-02T16:30:00' ) );

		$this->assertStringNotContainsString(
			'Sunday',
			$this->rendered_widget( self::WIDGET, $this->cook() )
		);
		$this->assertStringContainsString(
			'Sunday',
			$this->rendered_widget(
				self::WIDGET,
				$this->cook(),
				array(
					'show_date'   => 'yes',
					'date_format' => 'l',
				)
			)
		);
	}

	/**
	 * A card in a three-across grid has room for a few times, not eleven.
	 */
	public function test_it_can_be_held_to_the_next_few(): void {
		$this->screenings(
			array(
				10 => '2026-08-02T16:30:00',
				20 => '2026-08-04T19:15:00',
				30 => '2026-08-06T20:45:00',
			)
		);

		$html = $this->rendered_widget(
			self::WIDGET,
			$this->cook(),
			array(
				'time_format' => 'H:i',
				'limit'       => 2,
			)
		);

		$this->assertStringContainsString( '16:30', $html );
		$this->assertStringContainsString( '19:15', $html );
		$this->assertStringNotContainsString( '20:45', $html );
	}

	/**
	 * Programming the cinema has not announced is stored as a draft, and a
	 * widget that read drafts would publish next month's programme on a page
	 * nobody thought to check. Ticket 09 is where showing it deliberately lives.
	 */
	public function test_a_screening_that_is_only_planned_is_not_listed(): void {
		$this->screenings(
			array(),
			array(
				$this->session_payload(
					array(
						'Id'     => 10,
						'starts' => '2026-08-02T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'       => 20,
						'starts'   => '2026-08-04T19:15:00',
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
					)
				),
			)
		);

		$html = $this->rendered_widget( self::WIDGET, $this->cook(), array( 'time_format' => 'H:i' ) );

		$this->assertStringContainsString( '16:30', $html );
		$this->assertStringNotContainsString( '19:15', $html );
	}

	/**
	 * A film between seasons keeps its page. What it must not keep is an empty
	 * box where its times used to be.
	 */
	public function test_a_visitor_sees_nothing_when_a_film_has_no_screenings_left(): void {
		$this->screenings( array( 10 => '2026-08-02T16:30:00' ) );

		$this->arrange_programme( array(), array() );
		$this->sync_at( '2026-09-01 00:00:00' );

		$this->assertSame( '', $this->rendered_widget( self::WIDGET, $this->cook() ) );
	}

	/**
	 * Silent emptiness is the hardest state for a designer to diagnose: a card
	 * that renders nothing looks identical whether the widget is misconfigured,
	 * the token is missing, or the cinema simply has nothing on. So in the
	 * builder it says which, and names the screen to go and fix it on.
	 */
	public function test_the_editor_is_told_when_no_programme_has_synced(): void {
		$film = self::factory()->post->create( array( 'post_type' => 'veezi_film' ) );

		$this->in_the_editor();

		$html = $this->rendered_widget( self::WIDGET, $film );

		$this->assertStringContainsString( 'Settings', $html );
		$this->assertStringContainsString( 'Veezi', $html );
	}

	/**
	 * Once a sync has run the answer is a different one: this film has finished,
	 * which is not a configuration problem and must not read like one.
	 *
	 * Counting screenings would get this wrong, and it is the whole reason the
	 * sync writes down that it completed. A cinema between seasons has no
	 * screenings at all and is working perfectly — being told to go and check
	 * its connection would send somebody hunting a fault that is not there.
	 */
	public function test_the_editor_is_told_when_this_film_has_finished(): void {
		$this->screenings( array( 10 => '2026-08-02T16:30:00' ) );

		$this->arrange_programme( array(), array() );
		$this->sync_at( '2026-09-01 00:00:00' );

		$this->in_the_editor();

		$html = $this->rendered_widget( self::WIDGET, $this->cook() );

		$this->assertStringContainsString( 'no upcoming', $html );
		$this->assertStringNotContainsString( 'Settings', $html );
	}

	/**
	 * A loop item previews against whichever post Elementor picked, which is
	 * rarely a film — so this is what a designer sees for much of the time they
	 * spend building the card, and "no upcoming sessions" would send them
	 * looking for a fault in a card that is fine.
	 */
	public function test_the_editor_says_so_when_the_preview_is_not_a_film(): void {
		$this->screenings( array( 10 => '2026-08-02T16:30:00' ) );

		$this->in_the_editor();

		$html = $this->rendered_widget( self::WIDGET, self::factory()->post->create() );

		$this->assertStringContainsString( 'preview', $html );
		$this->assertStringNotContainsString( 'Settings', $html );
	}

	/**
	 * Booking links sit behind a bot challenge, so anything fetching one
	 * server-side gets a challenge page rather than an answer — and a page that
	 * checked six of them before rendering would be slow as well as wrong. The
	 * bootstrap turns any unarranged request into a failure, and nothing here
	 * arranges the ticketing host.
	 */
	public function test_rendering_a_card_never_calls_the_ticketing_site(): void {
		$this->screenings( array( 10 => '2026-08-02T16:30:00' ) );

		$before = count( $this->veezi->requests );

		$this->rendered_widget( self::WIDGET, $this->cook() );

		$this->assertSame( $before, count( $this->veezi->requests ) );
	}
}
