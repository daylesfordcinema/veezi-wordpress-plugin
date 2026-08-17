<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ComingSoon;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Presentation\Screening;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Programming the cinema has scheduled but not yet put on sale.
 *
 * Everything here turns on one switch. Planned sessions are the half of
 * `/v1/session` that Veezi's own web feed drops, and they are next month's
 * programme: real enough to be in the ticketing system, not necessarily
 * announced, and still liable to move. So the plugin holds them as drafts and
 * publishes nothing until somebody in the cinema decides to, and then only as
 * far ahead as they choose.
 *
 * Which makes the off state the one to get right. A test here that only ever
 * ran with the feature on would prove nothing about the state every site is in
 * on the day it installs this.
 */
final class ComingSoonTest extends TestCase {

	/**
	 * The moment every sync in this file runs at, in UTC. The cinema is ten
	 * hours ahead in August, so this is ten in the morning on Saturday the 1st
	 * where the films are actually showing — and the horizon is measured in the
	 * cinema's days, not the site's.
	 */
	private const RUN_AT = '2026-08-01 00:00:00';

	private function announce( int $days = ComingSoon::DEFAULT_DAYS ): void {
		( new Settings() )->update(
			array(
				'coming_soon'      => true,
				'coming_soon_days' => $days,
			)
		);
	}

	private function withdraw(): void {
		( new Settings() )->update( array( 'coming_soon' => false ) );
	}

	/**
	 * One film, screening only in a month that has not gone on sale yet.
	 *
	 * @param string $starts When the planned screening is, in the cinema's own
	 *                       wall-clock time.
	 */
	private function arrange_a_planned_season( string $starts = '2026-08-10T19:00:00' ): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 88,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => $starts,
					)
				),
			),
			array( $this->film_payload() )
		);
	}

	/**
	 * Which listings a film is filed under, by slug.
	 *
	 * @param  int $film A film record.
	 * @return array<int,string>
	 */
	private function listings( int $film ): array {
		$slugs = wp_get_object_terms( $film, ContentModel::LISTING, array( 'fields' => 'slugs' ) );

		return is_array( $slugs ) ? $slugs : array();
	}

	/**
	 * Criterion: the feature is off by default, and when off no planned content
	 * is exposed in any listing, page, feed or query.
	 *
	 * A site that has just installed the plugin has made no decision about
	 * next month's programme, and the plugin must not make it for them.
	 */
	public function test_nothing_planned_is_published_on_a_site_that_has_not_asked_for_it(): void {
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
		$this->assertSame( 'draft', get_post_status( $film ) );
		$this->assertSame( array(), $this->listings( $film ) );
	}

	/**
	 * Criterion: films with planned sessions inside a configurable horizon
	 * appear in a listing separate from the current programme.
	 *
	 * Separate is the word that matters. The listing is a term on the same
	 * taxonomy the current programme uses, so building it is the same dropdown
	 * a designer has already used once — and a film that is not on sale is not
	 * in the current one.
	 */
	public function test_switching_it_on_files_a_planned_film_under_coming_soon(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( 'publish', get_post_status( $film ) );
		$this->assertSame( array( ContentModel::COMING_SOON ), $this->listings( $film ) );
	}

	/**
	 * And its screenings are **not** published with it, however far the horizon
	 * reaches. A date is a promise, and a planned session is exactly the case
	 * where the cinema has not made one: the time can still move and nobody can
	 * hold them to it by buying a ticket.
	 *
	 * So coming-soon publication reaches the film and stops. That is what the
	 * settings screen has always described a coming-soon card as — poster,
	 * title, details, "On sale soon" — and why that card ships with no times
	 * and no button.
	 */
	public function test_a_planned_screening_is_never_published_however_near_it_is(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
		$this->assertSame( 'publish', get_post_status( $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * Criterion: behaviour is correct at the horizon boundary itself.
	 *
	 * The horizon is a number of the cinema's own days rather than a rolling
	 * count of hours, so "a fortnight" means through the end of the fourteenth
	 * day and not until ten past ten on the morning of it. A run at 10am on the
	 * 1st with a fortnight's horizon therefore reaches the last minute of the
	 * 15th, and no further.
	 *
	 * Read off the **film**, which is what the horizon decides now that no
	 * planned screening is ever published. With one screening the two questions
	 * are the same question: the film is talked about exactly when that
	 * screening is inside the horizon.
	 *
	 * @param string $starts   When the planned screening is, locally.
	 * @param string $expected What the film's status should be.
	 *
	 * @dataProvider screenings_either_side_of_the_horizon
	 */
	public function test_the_horizon_ends_with_the_last_day_it_covers( string $starts, string $expected ): void {
		$this->announce( 14 );
		$this->arrange_a_planned_season( $starts );
		$this->sync_at( self::RUN_AT );

		$this->assertSame( $expected, get_post_status( $this->film_record( 'film-cook' ) ) );
		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function screenings_either_side_of_the_horizon(): array {
		return array(
			'the last minute of the fourteenth day' => array( '2026-08-15T23:59:00', 'publish' ),
			'the first minute of the day after'     => array( '2026-08-16T00:01:00', 'draft' ),
		);
	}

	/**
	 * Criterion: the horizon is configurable.
	 *
	 * The point of the number is that a cinema can advertise the next fortnight
	 * without publishing three months of plans, so a screening the default
	 * would have reached must disappear when the horizon is shortened.
	 */
	public function test_a_shorter_horizon_publishes_less(): void {
		$this->announce( 2 );
		$this->arrange_a_planned_season( '2026-08-10T19:00:00' );
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
		$this->assertSame( 'draft', get_post_status( $film ) );
		$this->assertSame( array(), $this->listings( $film ) );
	}

	/**
	 * A film whose only announced screening is beyond the horizon is not
	 * coming soon — it is simply not being talked about yet. What it must not
	 * be is a published page with nothing on it.
	 */
	public function test_a_film_is_only_coming_soon_once_something_of_it_is(): void {
		$this->announce( 14 );
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 88,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-10T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'       => 99,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-11-10T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		// Neither screening is published — none ever is — so what the horizon
		// decides is whether the *film* is being talked about, and one screening
		// inside it is enough.
		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
		$this->assertSame( 'draft', get_post_status( $this->session_record( 99 ) ) );
		$this->assertSame(
			array( ContentModel::COMING_SOON ),
			$this->listings( $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * Criterion: a film with both on-sale and planned sessions appears
	 * correctly in both listings.
	 *
	 * Which for the coming-soon one means **not at all**. A film screening on
	 * Sunday is not coming soon; it is here. Reading the criterion the other way
	 * — filing such a film under both terms — leaves the phrase "coming soon"
	 * doing no work, and puts a film a visitor can buy a ticket for right now
	 * into the listing of things they cannot.
	 *
	 * So the two listings are mutually exclusive by construction, and what this
	 * criterion is really about is that the planned half must not corrupt the
	 * on-sale half: the film keeps its place in the current programme, with the
	 * dates that can actually be bought.
	 */
	public function test_a_film_showing_now_is_not_also_coming_soon(): void {
		$this->announce();
		$this->arrange_programme(
			array(
				$this->session_payload( array( 'Id' => 1 ) ),
				$this->session_payload(
					array(
						'Id'       => 2,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-10T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		$this->assertSame(
			array( ContentModel::NOW_SHOWING ),
			$this->listings( $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * And its planned dates are held back with it, everywhere — off its own
	 * card and out of the chronological listing.
	 *
	 * The rule is one rule, at the screening rather than at the film: a planned
	 * screening is published only while nothing of that film is on sale. The
	 * listing membership above then falls out of it rather than being decided a
	 * second time somewhere else.
	 *
	 * The cost is real and was chosen knowingly. These screenings are inside the
	 * horizon and are shown for every film that has nothing selling, so the
	 * calendar has a gap where this film's future dates would be — and a season
	 * already announced is retracted the moment its first date goes on sale.
	 * What is bought is what is advertised.
	 */
	public function test_a_film_showing_now_holds_its_planned_dates_back(): void {
		$this->announce();
		$this->arrange_programme(
			array(
				$this->session_payload( array( 'Id' => 1 ) ),
				$this->session_payload(
					array(
						'Id'       => 2,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-10T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		$this->assertSame( 'publish', get_post_status( $this->session_record( 1 ) ) );
		$this->assertSame( 'draft', get_post_status( $this->session_record( 2 ) ) );
		$this->assertCount( 1, Screening::for_film( $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * Which is decided over the whole film rather than one screening at a time.
	 * A planned date **earlier** than anything on sale is the case that would
	 * slip through a rule written per screening, and it is the one that matters
	 * most: a preview the cinema has not opened yet.
	 */
	public function test_a_planned_date_before_the_first_on_sale_one_is_held_back_too(): void {
		$this->announce();
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 1,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-02T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-06T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		$this->assertSame( 'draft', get_post_status( $this->session_record( 1 ) ) );
		$this->assertSame(
			array( ContentModel::NOW_SHOWING ),
			$this->listings( $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * Criterion: planned sessions never carry an active booking link.
	 *
	 * Veezi has none to give — the feed the links come from drops planned
	 * sessions entirely — so the honest answer is nothing at all, and a button
	 * bound to this renders with nowhere to go rather than pointing somewhere
	 * wrong.
	 */
	public function test_a_planned_screening_can_never_be_booked(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->assertSame( '', $this->bound( 'veezi-booking-url', $this->session_record( 88 ) ) );
	}

	/**
	 * Criterion: planned sessions show an on-sale-soon treatment.
	 *
	 * The same field that says "Sold out", because from a visitor's side these
	 * are the same question — can I get a ticket, and if not, why not.
	 */
	public function test_a_planned_screening_says_it_is_on_sale_soon(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->assertSame(
			'Tickets soon',
			$this->bound(
				'veezi-availability',
				$this->session_record( 88 ),
				array( 'on_sale_soon_text' => 'Tickets soon' )
			)
		);
	}

	/**
	 * And a film card lists no planned screening at all — not even as a row with
	 * no link.
	 *
	 * This is the same rule seen from the film's side. A time printed against a
	 * film is a date the cinema is standing behind, and the widget has no way to
	 * print one that means "probably". The card is left saying nothing about
	 * when, which is the honest answer while nothing is on sale.
	 */
	public function test_a_films_card_lists_no_planned_screening(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$html = $this->rendered_widget(
			'veezi-session-times',
			$this->film_record( 'film-cook' ),
			array(
				'time_format'       => 'H:i',
				'on_sale_soon_text' => 'On sale soon',
			)
		);

		$this->assertStringNotContainsString( '19:00', $html );
		$this->assertStringNotContainsString( '<a', $html );
	}

	/**
	 * A coming-soon film's own card names no date and offers nothing to press.
	 *
	 * The date is the point of this one. Every screening it has is planned, so
	 * there is nothing to headline: a card that named one would be committing
	 * the cinema to a time it can still move, and "coming soon" is precisely the
	 * phrase used when the answer to *when* is not settled. The booking link is
	 * empty for the same reason it always was.
	 */
	/**
	 * But it does still say "On sale soon", which is the whole message of the
	 * card and the only thing on it a visitor can act on — come back.
	 *
	 * That phrase used to arrive by a side road: the badge described the film's
	 * soonest *screening*, and a coming-soon film had a published one to
	 * describe. It has none now, so the wording has to come from the fact that
	 * the film is coming soon, which is a fact about the film and carries no date
	 * with it. Without this the shipped coming-soon card renders poster, title
	 * and details under a blank line.
	 */
	public function test_a_coming_soon_card_still_says_on_sale_soon(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->assertSame(
			'On sale soon',
			$this->bound( 'veezi-availability', $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * And a film with nothing scheduled at all says nothing, rather than
	 * promising a season that does not exist. The difference between the two is
	 * the listing the sync filed it under.
	 */
	public function test_a_film_between_seasons_says_nothing_about_availability(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->arrange_programme( array(), array() );
		$this->sync_at( self::RUN_AT );

		$this->assertSame( '', $this->bound( 'veezi-availability', $this->film_record( 'film-cook' ) ) );
	}

	public function test_a_coming_soon_card_names_no_date_and_sells_nothing(): void {
		$this->announce();
		$this->arrange_a_planned_season( '2026-08-04T19:00:00' );
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( '', $this->bound( 'veezi-session-time', $film ) );
		$this->assertSame( '', $this->bound( 'veezi-booking-url', $film ) );
	}

	/**
	 * Criterion: when off, no planned content is exposed in any listing.
	 *
	 * Switching it off has to be a retraction rather than merely a decision not
	 * to publish anything further. A cinema that announces a season and then
	 * pulls it is the case this exists for, and leaving what was already
	 * published on the site would be the opposite of what the switch says.
	 */
	public function test_switching_it_off_again_takes_back_what_it_published(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->withdraw();
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
		$this->assertSame( 'draft', get_post_status( $film ) );
		$this->assertSame( array(), $this->listings( $film ) );
	}

	/**
	 * But a film that has been on sale keeps its page, whatever this switch
	 * says. Its address may be in somebody's inbox or a search index, and
	 * ticket 08's promise is that the link goes on working after the season —
	 * which is a different promise from this one, and outranks it.
	 */
	public function test_a_film_that_has_been_on_sale_keeps_its_page(): void {
		$this->announce();
		$this->arrange_programme(
			array( $this->session_payload( array( 'Id' => 1 ) ) ),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 2,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-10T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->withdraw();
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( 'publish', get_post_status( $film ) );
		$this->assertSame( array(), $this->listings( $film ) );
		$this->assertSame( 'draft', get_post_status( $this->session_record( 2 ) ) );
	}

	/**
	 * Criterion: when off, no planned content is exposed in any query.
	 *
	 * The two facts the sync denormalises onto a film — when it next screens
	 * and how many screenings are left — are read by listings rather than
	 * rendered, which is exactly why they are easy to leave describing
	 * something a visitor cannot see. A film on sale on Thursday with an
	 * unannounced preview on Tuesday must not report Tuesday, and must not
	 * count a screening nobody can find.
	 *
	 * True in both positions of the switch, and for two different reasons —
	 * off, because nothing planned is published at all; on, because a film with
	 * something on sale holds its planned dates back anyway. Asserted across
	 * both so that neither reason can quietly stop being the case.
	 *
	 * @param bool $announcing Whether the cinema has asked for this.
	 *
	 * @dataProvider the_switch_either_way
	 */
	public function test_a_films_stored_facts_describe_what_is_published( bool $announcing ): void {
		if ( $announcing ) {
			$this->announce();
		}

		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 1,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-04T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-06T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( '1', get_post_meta( $film, ContentModel::FILM_SESSION_COUNT, true ) );
		$this->assertSame(
			'2026-08-06 09:00',
			gmdate( 'Y-m-d H:i', (int) get_post_meta( $film, ContentModel::FILM_NEXT_SCREENING, true ) )
		);
	}

	/**
	 * A coming-soon film has both of those facts **empty**, because both of them
	 * are dates or counts of dates, and it has none it can name.
	 *
	 * Its place in a listing does not depend on them: the sort falls back to when
	 * the film is *scheduled*, which the sync knows and never writes down. That
	 * fallback was built for a film with nothing published, and this is now the
	 * ordinary case of one rather than the edge case it was.
	 */
	public function test_a_coming_soon_film_carries_neither_of_those_facts(): void {
		$this->announce();
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 1,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-04T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'       => 2,
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-06T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( '0', get_post_meta( $film, ContentModel::FILM_SESSION_COUNT, true ) );
		$this->assertSame( '', get_post_meta( $film, ContentModel::FILM_NEXT_SCREENING, true ) );

		// And it is still in the listing it belongs to, which is the point of
		// asserting the two above are empty rather than simply not asserting.
		$this->assertSame( array( ContentModel::COMING_SOON ), $this->listings( $film ) );
	}

	/**
	 * A film announced and then dropped from the schedule altogether is an
	 * announcement of something that is not happening. Veezi changing its mind
	 * has to retract it as surely as the cinema changing the setting does — and
	 * the sweep that handles a film leaving the schedule deliberately never
	 * un-publishes one, because ticket 08 needs it not to.
	 */
	public function test_a_film_announced_and_then_dropped_comes_down_again(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$film = $this->film_record( 'film-cook' );
		$this->assertSame( 'publish', get_post_status( $film ) );

		$this->arrange_programme( array(), array() );
		$this->sync_at( self::RUN_AT );

		$this->assertSame( 'draft', get_post_status( $film ) );
	}

	/**
	 * The chronological listing holds what can be bought, and **this switch does
	 * not move it** either way. That is the whole of the fix: it used to follow
	 * the switch, so turning coming-soon on put a week of dates the cinema had
	 * not committed to into the listing, as rows that could not be clicked.
	 *
	 * Kept as a data-provider case in both positions rather than rewritten as one
	 * assertion, because "the switch does not reach this" is the claim, and only
	 * throwing the switch can make it.
	 *
	 * Two films, because one would not do: a planned date only reaches the
	 * listing while its own film has nothing on sale, so a single film carrying
	 * both kinds of session would come out as one row whichever way the switch
	 * is thrown, and prove nothing.
	 *
	 * @param bool $announcing Whether the cinema has asked for this.
	 * @param int  $expected   How many screenings the listing comes back with.
	 *
	 * @dataProvider the_switch_in_both_positions
	 */
	public function test_the_chronological_listing_follows_the_switch( bool $announcing, int $expected ): void {
		if ( $announcing ) {
			$this->announce();
		}

		$this->arrange_programme(
			array(
				$this->session_payload( array( 'Id' => 1 ) ),
				$this->session_payload(
					array(
						'Id'       => 2,
						'FilmId'   => 'film-later',
						'Title'    => 'The Second Feature',
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-10T19:00:00',
					)
				),
			),
			array(
				$this->film_payload(),
				$this->film_payload(
					array(
						'Id'    => 'film-later',
						'Title' => 'The Second Feature',
					)
				),
			)
		);
		$this->sync_at( self::RUN_AT );

		$this->assertCount(
			$expected,
			get_posts(
				array(
					'post_type'   => ContentModel::SESSION,
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
					'orderby'     => array( 'menu_order' => 'ASC' ),
				)
			)
		);
	}

	/**
	 * @return array<string,array{0:bool,1:int}>
	 */
	public static function the_switch_in_both_positions(): array {
		return array(
			'off — only what is on sale' => array( false, 1 ),
			'on — still only that'       => array( true, 1 ),
		);
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public static function the_switch_either_way(): array {
		return array(
			'not announcing' => array( false ),
			'announcing'     => array( true ),
		);
	}
}
