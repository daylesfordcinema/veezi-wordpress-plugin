<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ComingSoon;
use Veezi\WordPress\ContentModel;
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
	 * And its screenings are published with it, because a coming-soon card
	 * whose times were all still drafts would be a film with no dates against
	 * it — which is the one thing somebody planning ahead came for.
	 */
	public function test_a_planned_screening_inside_the_horizon_is_published(): void {
		$this->announce();
		$this->arrange_a_planned_season();
		$this->sync_at( self::RUN_AT );

		$this->assertSame( 'publish', get_post_status( $this->session_record( 88 ) ) );
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
	 * @param string $starts   When the planned screening is, locally.
	 * @param string $expected What the record's status should be.
	 *
	 * @dataProvider screenings_either_side_of_the_horizon
	 */
	public function test_the_horizon_ends_with_the_last_day_it_covers( string $starts, string $expected ): void {
		$this->announce( 14 );
		$this->arrange_a_planned_season( $starts );
		$this->sync_at( self::RUN_AT );

		$this->assertSame( $expected, get_post_status( $this->session_record( 88 ) ) );
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

		$this->assertSame( 'publish', get_post_status( $this->session_record( 88 ) ) );
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
	 * Both, not one or the other. It is showing this week, which is what the
	 * current programme is for; and there are more dates coming that nobody can
	 * book yet, which is what the other listing is for. Filing it under only one
	 * would mean a visitor reading the wrong listing never learns half of it.
	 */
	public function test_a_film_showing_now_with_more_to_come_is_in_both_listings(): void {
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

		$listings = $this->listings( $this->film_record( 'film-cook' ) );

		sort( $listings );

		$this->assertSame(
			array( ContentModel::COMING_SOON, ContentModel::NOW_SHOWING ),
			$listings
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
	 * And inside a film card, where the same words come from the widget's own
	 * panel. A row with no link, which is what an unsellable screening has
	 * looked like since ticket 03.
	 */
	public function test_a_films_card_shows_a_planned_screening_without_a_link(): void {
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

		$this->assertStringContainsString( '19:00', $html );
		$this->assertStringContainsString( 'On sale soon', $html );
		$this->assertStringNotContainsString( '<a', $html );
	}

	/**
	 * A card's button and its headline time both skip past a screening nobody
	 * can buy a ticket for, exactly as they skip a sold-out one — so a film
	 * showing tomorrow does not advertise itself as unavailable because of a
	 * preview screening the week before.
	 */
	public function test_a_cards_button_skips_a_planned_screening(): void {
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

		$film = $this->film_record( 'film-cook' );

		$this->assertSame(
			'https://ticketing.example.test/purchase?session=2',
			$this->bound( 'veezi-booking-url', $film )
		);
		$this->assertSame( 'August 6, 2026 7:00 pm', $this->bound( 'veezi-session-time', $film ) );
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
	 * The chronological listing is a query over sessions with nothing
	 * configured, so it follows this switch without anybody rebuilding it —
	 * and while the switch is off it cannot show a planned screening even by
	 * accident, because there is no published record for it to find.
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
						'Status'   => 'Planned',
						'SalesVia' => array( 'POS' ),
						'starts'   => '2026-08-10T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
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
			'on — what is planned too'   => array( true, 2 ),
		);
	}
}
