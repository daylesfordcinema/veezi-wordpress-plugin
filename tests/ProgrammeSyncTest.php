<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\Client;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Sync;
use Veezi\WordPress\SyncResult;
use Veezi\WordPress\Token;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Syncing the cinema's programme into WordPress.
 *
 * Everything here runs the real sync against real WordPress, with only the
 * network faked, and asserts on what an administrator or a visitor would end up
 * seeing: which records exist, what they say, and what a second sync does. The
 * shape of the code in between is nobody's business but its own.
 */
final class ProgrammeSyncTest extends TestCase {

	private function sync_at( string $moment = '2026-08-01 00:00:00' ): SyncResult {
		$this->store_token( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' );

		return ( new Sync( new Client( Token::resolve( new Settings() ) ) ) )
			->run( new DateTimeImmutable( $moment, new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * @param  string $post_type Which kind of record to gather up.
	 * @return array<int,int>
	 */
	private function records( string $post_type ): array {
		return get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * The record the sync made for one of Veezi's identifiers.
	 *
	 * @param string $post_type Which kind of record.
	 * @param string $meta_key  The field holding the upstream identifier.
	 * @param string $upstream  The identifier itself.
	 */
	private function record_for( string $post_type, string $meta_key, string $upstream ): int {
		$found = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => array_keys( get_post_stati() ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => $meta_key,
				'meta_value'  => $upstream,
			)
		);

		$this->assertNotEmpty( $found, "The sync made no {$post_type} record for {$upstream}." );

		return (int) $found[0];
	}

	private function session_record( int $upstream_id ): int {
		return $this->record_for( ContentModel::SESSION, ContentModel::SESSION_ID, (string) $upstream_id );
	}

	private function film_record( string $upstream_id ): int {
		return $this->record_for( ContentModel::FILM, ContentModel::FILM_ID, $upstream_id );
	}

	public function test_a_sync_creates_one_record_per_film_and_one_per_session(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 1,
						'FilmId' => 'film-cook',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'FilmId' => 'film-cook',
						'starts' => '2026-08-03T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 3,
						'FilmId' => 'film-lighthouse',
						'Title'  => 'The Lighthouse Keeper',
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

		$result = $this->sync_at();

		$this->assertTrue( $result->is_success(), $result->message() );
		$this->assertCount( 2, $this->records( ContentModel::FILM ) );
		$this->assertCount( 3, $this->records( ContentModel::SESSION ) );
	}

	public function test_a_film_record_carries_what_a_visitor_reads(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$film = get_post( $this->film_record( 'film-cook' ) );

		$this->assertSame( 'The Cook’s Tale', $film->post_title );
		$this->assertStringContainsString( 'failing restaurant', $film->post_content );
		$this->assertSame( '100', get_post_meta( $film->ID, ContentModel::FILM_RUNTIME, true ) );
		$this->assertSame( 'Mirador Pictures', get_post_meta( $film->ID, ContentModel::FILM_DISTRIBUTOR, true ) );
		$this->assertSame( '2026-07-30', get_post_meta( $film->ID, ContentModel::FILM_RELEASED, true ) );
		$this->assertSame(
			'https://www.youtube.com/watch?v=abcdefghijk',
			get_post_meta( $film->ID, ContentModel::FILM_TRAILER, true )
		);
	}

	public function test_a_film_is_filed_under_its_genres_and_classification(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$film = $this->film_record( 'film-cook' );

		$genres = wp_get_object_terms( $film, ContentModel::GENRE, array( 'fields' => 'names' ) );
		sort( $genres );

		$this->assertSame( array( 'Comedy', 'Drama' ), $genres );
		$this->assertSame(
			array( 'PG' ),
			wp_get_object_terms( $film, ContentModel::CLASSIFICATION, array( 'fields' => 'names' ) )
		);
	}

	public function test_a_film_with_a_session_still_to_come_is_listed_as_now_showing(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2026-08-02T16:30:00' ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-08-01 00:00:00' );

		$this->assertSame(
			array( ContentModel::NOW_SHOWING ),
			wp_get_object_terms( $this->film_record( 'film-cook' ), ContentModel::LISTING, array( 'fields' => 'slugs' ) )
		);
	}

	/**
	 * The season is over: nothing is left to sell, so the film drops out of the
	 * current listing. Its record stays, because somebody may still be holding
	 * a link to it.
	 */
	public function test_a_film_whose_sessions_have_all_passed_is_no_longer_now_showing(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2026-08-02T16:30:00' ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-09-01 00:00:00' );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( array(), wp_get_object_terms( $film, ContentModel::LISTING, array( 'fields' => 'slugs' ) ) );
		$this->assertSame( 'publish', get_post_status( $film ) );
	}

	public function test_an_on_sale_session_carries_a_booking_link(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'Id' => 77 ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame(
			'https://ticketing.example.test/purchase?session=77',
			get_post_meta( $this->session_record( 77 ), ContentModel::SESSION_BOOKING, true )
		);
	}

	/**
	 * Planned sessions are the reason the sync reads the unfiltered endpoint at
	 * all: the web-session feed drops them entirely. They arrive with no
	 * booking link, because upstream has none to give.
	 */
	public function test_a_planned_session_is_recorded_but_has_nothing_to_book(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 88,
						'Status' => 'Planned',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$session = $this->session_record( 88 );

		$this->assertSame( 'planned', get_post_meta( $session, ContentModel::SESSION_STATUS, true ) );
		$this->assertSame( '', get_post_meta( $session, ContentModel::SESSION_BOOKING, true ) );
	}

	/**
	 * Planned programming has not necessarily been announced, so nothing about
	 * it is published until the cinema says so. The records exist — an
	 * administrator can see them — but no query a visitor triggers will find
	 * them.
	 */
	public function test_planned_programming_is_not_published(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 88,
						'Status' => 'Planned',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame( 'draft', get_post_status( $this->session_record( 88 ) ) );
		$this->assertSame( 'draft', get_post_status( $this->film_record( 'film-cook' ) ) );
	}

	public function test_a_film_with_both_on_sale_and_planned_sessions_is_published(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 1,
						'Status' => 'Open',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'Status' => 'Planned',
						'starts' => '2026-09-02T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame( 'publish', get_post_status( $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * Veezi says "16:30" and means half past four where the cinema is. This
	 * site's WordPress is set to UTC, which is what an install nobody has
	 * configured looks like — so anything trusting the site's timezone would put
	 * the session ten hours out while looking entirely plausible on the page.
	 */
	public function test_a_showtime_means_the_cinemas_local_time_not_the_sites(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );

		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 5,
						'starts' => '2026-08-02T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$session = $this->session_record( 5 );

		// 4:30pm on 2 August in Sydney is 6:30am UTC.
		$this->assertSame(
			( new DateTimeImmutable( '2026-08-02 06:30:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(),
			(int) get_post_meta( $session, ContentModel::SESSION_STARTS, true )
		);

		$this->assertStringContainsString(
			'4:30 pm',
			get_post_meta( $session, ContentModel::SESSION_STARTS_TEXT, true )
		);
	}

	/**
	 * The zone is whatever the cinema's own account says it is, not a guess and
	 * not a constant — which is the whole reason this plugin can be handed to
	 * another Veezi cinema.
	 */
	public function test_the_timezone_is_the_one_the_cinema_reports(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 5,
						'starts' => '2026-08-02T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);
		$this->veezi->will_return(
			'/v1/site',
			$this->site_payload( array( 'TimeZoneIdentifier' => 'New Zealand Standard Time' ) )
		);

		$this->sync_at();

		// The same reading of the same clock face, two hours earlier in real time.
		$this->assertSame(
			( new DateTimeImmutable( '2026-08-02 04:30:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(),
			(int) get_post_meta( $this->session_record( 5 ), ContentModel::SESSION_STARTS, true )
		);
	}

	public function test_a_session_records_when_it_finishes_as_well_as_when_it_starts(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'      => 5,
						'starts'  => '2026-08-02T16:30:00',
						'runtime' => 90,
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$session = $this->session_record( 5 );

		// Ten minutes of ads, then ninety of film: the feature ends at 6:10pm.
		$this->assertSame(
			( new DateTimeImmutable( '2026-08-02 08:10:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(),
			(int) get_post_meta( $session, ContentModel::SESSION_ENDS, true )
		);
		$this->assertStringContainsString(
			'6:10 pm',
			get_post_meta( $session, ContentModel::SESSION_ENDS_TEXT, true )
		);
	}

	/**
	 * Southern-hemisphere summer time. Both of these are evening sessions and
	 * both must read as evening sessions; the instants they map to differ by an
	 * hour more than the calendar suggests.
	 */
	public function test_a_showtime_is_right_on_both_sides_of_a_daylight_saving_change(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 10,
						'starts' => '2026-01-15T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 11,
						'starts' => '2026-07-15T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-01-01 00:00:00' );

		$summer = $this->session_record( 10 );
		$winter = $this->session_record( 11 );

		foreach ( array( $summer, $winter ) as $session ) {
			$this->assertStringContainsString(
				'7:00 pm',
				get_post_meta( $session, ContentModel::SESSION_STARTS_TEXT, true ),
				'A 7pm session is a 7pm session in either half of the year.'
			);
		}

		$this->assertSame(
			gmdate( 'Y-m-d H:i', (int) get_post_meta( $summer, ContentModel::SESSION_STARTS, true ) ),
			'2026-01-15 08:00',
			'Summer time is UTC+11.'
		);
		$this->assertSame(
			gmdate( 'Y-m-d H:i', (int) get_post_meta( $winter, ContentModel::SESSION_STARTS, true ) ),
			'2026-07-15 09:00',
			'Standard time is UTC+10.'
		);
	}

	public function test_sessions_can_be_ordered_by_when_they_happen(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 3,
						'starts' => '2026-08-04T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 1,
						'starts' => '2026-08-02T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-03T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$ordered = get_posts(
			array(
				'post_type'   => ContentModel::SESSION,
				'post_status' => 'any',
				'numberposts' => -1,
				'meta_key'    => ContentModel::SESSION_STARTS,
				'orderby'     => 'meta_value_num',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);

		$this->assertSame(
			array( 1, 2, 3 ),
			array_map(
				static fn ( int $id ): int => (int) get_post_meta( $id, ContentModel::SESSION_ID, true ),
				$ordered
			)
		);
	}

	/**
	 * The film catalogue lists everything the cinema has ever loaded, test rows
	 * included, and every one of them reports itself as active. A listing built
	 * from it would advertise films that are not showing and never will.
	 */
	public function test_films_nobody_has_scheduled_are_not_imported(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array(
				$this->film_payload(),
				$this->film_payload(
					array(
						'Id'    => 'film-test-row',
						'Title' => 'TEST Do Not Book',
					)
				),
				$this->film_payload(
					array(
						'Id'    => 'film-last-year',
						'Title' => 'Last Year’s Feature',
					)
				),
			)
		);

		$this->sync_at();

		$titles = array_map( 'get_the_title', $this->records( ContentModel::FILM ) );

		$this->assertSame( array( 'The Cook’s Tale' ), $titles );
	}

	/**
	 * A film can be scheduled before its catalogue entry exists. The session is
	 * the thing being sold, so it goes on the site regardless — carrying the
	 * title Veezi puts on the session itself.
	 */
	public function test_a_session_whose_film_is_missing_still_appears(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 42,
						'FilmId' => 'film-not-in-catalogue',
						'Title'  => 'A Surprise Screening',
					)
				),
			),
			array()
		);

		$result = $this->sync_at();
		$this->assertTrue( $result->is_success(), $result->message() );

		$session = $this->session_record( 42 );

		$this->assertStringContainsString( 'A Surprise Screening', get_the_title( $session ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $session, ContentModel::SESSION_STARTS, true ) );
		$this->assertSame( 0, (int) get_post_meta( $session, ContentModel::SESSION_FILM, true ) );
		$this->assertCount( 0, $this->records( ContentModel::FILM ) );
	}

	public function test_a_session_points_at_its_film(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'Id' => 9 ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame(
			$this->film_record( 'film-cook' ),
			(int) get_post_meta( $this->session_record( 9 ), ContentModel::SESSION_FILM, true )
		);
	}

	public function test_sold_out_and_nearly_sold_out_sessions_are_marked(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'             => 1,
						'TicketsSoldOut' => true,
					)
				),
				$this->session_payload(
					array(
						'Id'             => 2,
						'FewTicketsLeft' => true,
					)
				),
				$this->session_payload( array( 'Id' => 3 ) ),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertSame( '1', get_post_meta( $this->session_record( 1 ), ContentModel::SESSION_SOLD_OUT, true ) );
		$this->assertSame( '', get_post_meta( $this->session_record( 1 ), ContentModel::SESSION_FEW_LEFT, true ) );

		$this->assertSame( '1', get_post_meta( $this->session_record( 2 ), ContentModel::SESSION_FEW_LEFT, true ) );
		$this->assertSame( '', get_post_meta( $this->session_record( 2 ), ContentModel::SESSION_SOLD_OUT, true ) );

		$this->assertSame( '', get_post_meta( $this->session_record( 3 ), ContentModel::SESSION_SOLD_OUT, true ) );
		$this->assertSame( '', get_post_meta( $this->session_record( 3 ), ContentModel::SESSION_FEW_LEFT, true ) );
	}

	/**
	 * How many seats are sold is the cinema's business. Keeping it out of the
	 * database entirely is stronger than filtering it at render time, because
	 * there is then nothing to leak through a REST route, an export, or a
	 * developer's careless template.
	 */
	public function test_box_office_figures_are_never_written_down(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$stored = '';

		foreach ( array( ContentModel::FILM, ContentModel::SESSION ) as $post_type ) {
			foreach ( $this->records( $post_type ) as $id ) {
				$stored .= wp_json_encode( get_post_meta( $id ) ) . get_post( $id )->post_content;
			}
		}

		foreach ( array( '4321', '8765', '765', '987', 'Weekday Matinee Concession' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored );
		}

		$this->assertStringNotContainsString( 'seat', strtolower( $stored ) );
		$this->assertStringNotContainsString( 'price', strtolower( $stored ) );
	}

	public function test_a_second_identical_sync_creates_nothing_and_changes_nothing(): void {
		$this->arrange_programme(
			array(
				$this->session_payload( array( 'Id' => 1 ) ),
				$this->session_payload(
					array(
						'Id'     => 2,
						'Status' => 'Planned',
						'starts' => '2026-09-02T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$before = array_merge( $this->records( ContentModel::FILM ), $this->records( ContentModel::SESSION ) );

		$writes = 0;
		add_action(
			'save_post',
			static function () use ( &$writes ): void {
				++$writes;
			}
		);

		$this->sync_at();

		$this->assertSame( 0, $writes, 'Nothing upstream changed, so nothing here should have been rewritten.' );
		$this->assertSame(
			$before,
			array_merge( $this->records( ContentModel::FILM ), $this->records( ContentModel::SESSION ) )
		);
	}

	/**
	 * Apostrophes are the classic way a record rewrites itself forever: stored
	 * one way, compared another, and every sync decides it has changed.
	 */
	public function test_a_title_with_an_apostrophe_settles_after_the_first_sync(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'Title' => 'Ocean’s Eleven' ) ) ),
			array(
				$this->film_payload(
					array(
						'Title'    => "Ocean's Eleven",
						'Synopsis' => "Danny's crew hits three casinos.",
					)
				),
			)
		);

		$this->sync_at();

		$writes = 0;
		add_action(
			'save_post',
			static function () use ( &$writes ): void {
				++$writes;
			}
		);

		$this->sync_at();

		$this->assertSame( 0, $writes );
		$this->assertSame( "Ocean's Eleven", get_post( $this->film_record( 'film-cook' ) )->post_title );
	}

	public function test_a_changed_showtime_is_picked_up_without_making_a_second_record(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 1,
						'starts' => '2026-08-02T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 1,
						'starts' => '2026-08-02T20:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertCount( 1, $this->records( ContentModel::SESSION ) );
		$this->assertStringContainsString(
			'8:00 pm',
			get_post_meta( $this->session_record( 1 ), ContentModel::SESSION_STARTS_TEXT, true )
		);
	}

	/**
	 * An outage at the ticketing provider must not blank the cinema's website.
	 * Whatever synced last stays exactly where it is.
	 */
	public function test_a_sync_that_cannot_reach_veezi_leaves_the_programme_standing(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->veezi->will_fail( '/v1/session' );

		$result = $this->sync_at();

		$this->assertFalse( $result->is_success() );
		$this->assertCount( 1, $this->records( ContentModel::FILM ) );
		$this->assertCount( 1, $this->records( ContentModel::SESSION ) );
	}

	/**
	 * The one that matters most. A screening the cinema has cancelled must stop
	 * being sold — a stale record with a live booking link keeps taking people's
	 * money for something that is not happening, which is worse than showing
	 * them nothing at all.
	 */
	public function test_a_cancelled_screening_stops_being_offered(): void {
		$this->arrange_programme(
			array(
				$this->session_payload( array( 'Id' => 1 ) ),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-03T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();
		$this->assertCount( 2, $this->records( ContentModel::SESSION ) );

		// Veezi drops session 2: it is off.
		$this->arrange_programme(
			array( $this->session_payload( array( 'Id' => 1 ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$this->assertCount( 1, $this->records( ContentModel::SESSION ) );
		$this->assertSame( 1, (int) get_post_meta( $this->session_record( 1 ), ContentModel::SESSION_ID, true ) );
	}

	/**
	 * The film itself survives — somebody may be holding a link — but it stops
	 * claiming to be showing.
	 */
	public function test_a_film_pulled_from_the_schedule_leaves_the_listing_but_keeps_its_page(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload() )
		);

		$this->sync_at();
		$film = $this->film_record( 'film-cook' );
		$this->assertNotEmpty( wp_get_object_terms( $film, ContentModel::LISTING, array( 'fields' => 'slugs' ) ) );

		$this->arrange_programme( array(), array( $this->film_payload() ) );

		$this->sync_at();

		$this->assertSame( array(), wp_get_object_terms( $film, ContentModel::LISTING, array( 'fields' => 'slugs' ) ) );
		$this->assertSame( 'publish', get_post_status( $film ) );
		$this->assertCount( 1, $this->records( ContentModel::FILM ) );
	}

	/**
	 * A session can be on sale at the box office and not online — Veezi says so
	 * in `SalesVia`, and its own web feed leaves those out. The screening is
	 * real, so it belongs on the programme; there is simply nothing to link to.
	 */
	public function test_a_session_not_sold_online_appears_with_nothing_to_click(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'       => 12,
						'SalesVia' => array( 'POS' ),
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$session = $this->session_record( 12 );

		$this->assertSame( '', get_post_meta( $session, ContentModel::SESSION_BOOKING, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $session, ContentModel::SESSION_STARTS, true ) );
	}

	/**
	 * Somebody tidying the admin trashes a synced film. The next sync must find
	 * it again rather than making a second one and leaving the site with two.
	 */
	public function test_trashing_a_record_does_not_produce_a_duplicate(): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload() )
		);

		$this->sync_at();
		wp_trash_post( $this->film_record( 'film-cook' ) );

		$this->sync_at();

		$this->assertCount( 1, $this->records( ContentModel::FILM ) );
	}

	/**
	 * Ordering a listing by next screening is a question the page builder
	 * cannot ask, so the sync answers it in advance.
	 */
	public function test_a_film_records_when_it_next_screens_and_how_often(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 1,
						'starts' => '2026-08-05T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-02T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 3,
						'starts' => '2026-07-20T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at( '2026-08-01 00:00:00' );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame(
			'2026-08-02 06:30',
			gmdate( 'Y-m-d H:i', (int) get_post_meta( $film, ContentModel::FILM_NEXT_SCREENING, true ) ),
			'The next screening is the soonest one still to come, not the earliest on record.'
		);
		$this->assertSame( '3', get_post_meta( $film, ContentModel::FILM_SESSION_COUNT, true ) );
	}

	/**
	 * Titles are stripped rather than left to WordPress, which only filters them
	 * for users without the unfiltered_html capability — so a cron sync and an
	 * administrator's sync would otherwise store different bytes and spend
	 * forever undoing each other.
	 */
	public function test_markup_in_a_title_is_not_stored(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'Title' => 'The <b>Cook\'s</b> Tale' ) ) ),
			array( $this->film_payload( array( 'Title' => 'The <b>Cook\'s</b> Tale' ) ) )
		);

		$this->sync_at();

		$this->assertSame( "The Cook's Tale", get_post( $this->film_record( 'film-cook' ) )->post_title );

		$writes = 0;
		add_action(
			'save_post',
			static function () use ( &$writes ): void {
				++$writes;
			}
		);

		$this->sync_at();

		$this->assertSame( 0, $writes, 'A stripped title must compare equal to itself on the next run.' );
	}

	public function test_a_sync_says_what_it_brought_in(): void {
		$this->arrange_programme(
			array(
				$this->session_payload( array( 'Id' => 1 ) ),
				$this->session_payload(
					array(
						'Id'     => 2,
						'starts' => '2026-08-03T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$message = $this->sync_at()->message();

		$this->assertStringContainsString( '1 film', $message );
		$this->assertStringContainsString( '2 sessions', $message );
	}
}
